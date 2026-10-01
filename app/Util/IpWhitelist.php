<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\User;
use App\Model\UserIpWhitelist;
use App\Model\UserLog;
use Kernel\Exception\JSONException;

/**
 * 对接白名单 IP：对接接口（店铺共享 / API）用余额下单时的来源放行清单。
 *
 * 添加第一条即启用，与是否开启两步验证无关：清单不为空时只放行清单内的来源。清单为空时，开启了资金操作
 * 二次验证的会员一律拒绝（对接接口是服务器对服务器调用、以 app_key 鉴权，没法弹动态码），其余不限制。
 * 来源 IP 取 Client::getAddress()：只有直连来源是受信代理时才认转发头，客户端伪造不了。
 */
class IpWhitelist
{
    /** 每个会员最多登记的条数 */
    public const LIMIT = 20;

    /** 被拒记录的安全日志事件码（会员端据此列出「最近被拒的来源」） */
    public const LOG_DENIED = 'api_ip_denied';

    //网段下限：挡掉 0.0.0.0/0 这类等于全开的大网段
    private const MIN_PREFIX_V4 = 16;
    private const MIN_PREFIX_V6 = 48;

    //同一来源被拒，这段时间内只记一条日志（秒）
    private const DENY_LOG_WINDOW = 600;

    //「最近被拒的来源」回看天数与展示条数
    private const DENIED_LOOKBACK_DAYS = 7;
    private const DENIED_SHOW = 5;

    //最近放行时间的刷新间隔（秒），免得每一单都写一次库
    private const TOUCH_INTERVAL = 60;

    /**
     * 规范化会员填写的 IP 或网段：单个 IP 与 getAddress() 同一写法（IPv4 映射地址折成 IPv4），网段抹掉主机位。
     * @throws JSONException
     */
    public static function normalize(string $raw): string
    {
        $raw = trim($raw);
        $parts = explode('/', $raw, 2);
        $ip = ($raw !== '' && strlen($raw) <= 64 && filter_var($parts[0], FILTER_VALIDATE_IP) !== false)
            ? Client::normalizeIp($parts[0])
            : null;
        if ($ip === null) {
            throw new JSONException("请输入正确的 IP 地址或网段，例如 203.0.113.8 或 203.0.113.0/24");
        }
        if (!isset($parts[1])) {
            return $ip;
        }

        $isV6 = str_contains($ip, ':');
        $max = $isV6 ? 128 : 32;
        $min = $isV6 ? self::MIN_PREFIX_V6 : self::MIN_PREFIX_V4;
        if (!preg_match('/^\d{1,3}$/D', $parts[1]) || (int)$parts[1] > $max) {
            throw new JSONException("网段写法不正确，斜杠后应为 {$min}～{$max} 的数字");
        }
        $prefix = (int)$parts[1];
        if ($prefix < $min) {
            throw new JSONException("网段范围过大：IPv4 最大到 /" . self::MIN_PREFIX_V4 . "，IPv6 最大到 /" . self::MIN_PREFIX_V6);
        }
        if ($prefix === $max) {
            return $ip;
        }

        $binary = (string)inet_pton($ip);
        $whole = intdiv($prefix, 8);
        $network = substr($binary, 0, $whole);
        if ($prefix % 8 !== 0) {
            $network .= chr(ord($binary[$whole]) & ((0xff << (8 - $prefix % 8)) & 0xff));
            $whole++;
        }
        $network .= str_repeat("\0", strlen($binary) - $whole);
        return inet_ntop($network) . '/' . $prefix;
    }

    /**
     * 条目（单个 IP 或网段）是否覆盖来源 IP。两边都须是规范化写法。
     */
    public static function covers(string $entry, string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        return str_contains($entry, '/') ? Client::ipMatchesRange($ip, $entry) : $entry === $ip;
    }

    /**
     * 对接接口用余额下单前的来源校验（Shared\Commodity::trade 调用）。
     * 报错不带来源 IP：下游可能把上游报错原样回给顾客，带上就等于把下游服务器的真实 IP 公开出去；
     * 被拒的来源改为记进会员自己的安全日志，在白名单页一键加入。
     * @throws JSONException
     */
    public static function guardApiTrade(User $user): void
    {
        Schema::ensureUserIpWhitelistTable();
        $entries = UserIpWhitelist::query()->where('user_id', (int)$user->id)->get();
        $ip = Client::getAddress();

        if ($entries->isEmpty()) {
            if (!FundGuard::required($user)) {
                return;
            }
            self::recordDenied($user, $ip);
            throw new JSONException("该账号已开启资金二次验证，必须先登记对接白名单 IP 才能通过对接接口用余额下单。请登录该账号的会员中心，在「对接白名单」（或「两步验证」页）把服务器 IP 加入白名单；被拒的 IP 会列在那里");
        }
        if (self::hit($entries, $ip)) {
            return;
        }
        self::recordDenied($user, $ip);
        throw new JSONException("调用方 IP 不在该账号的对接白名单内。请登录该账号的会员中心，在「对接白名单」（或「两步验证」页）把服务器 IP 加入白名单；被拒的 IP 会列在那里");
    }

    /**
     * 命中判定：返回覆盖来源 IP 的条目（精确 IP 优先于网段，顺带刷新最近放行时间），否则 null。
     * @param iterable<UserIpWhitelist> $entries
     */
    private static function hit(iterable $entries, string $ip): ?UserIpWhitelist
    {
        if ($ip === '') {
            return null;
        }

        $hit = null;
        foreach ($entries as $entry) {
            if (!self::covers((string)$entry->ip, $ip)) {
                continue;
            }
            $hit = $entry;
            if ((string)$entry->ip === $ip) {
                break;
            }
        }

        if ($hit && (!$hit->last_used_time || strtotime((string)$hit->last_used_time) < time() - self::TOUCH_INTERVAL)) {
            try {
                UserIpWhitelist::query()->where('id', $hit->id)->update(['last_used_time' => Date::current()]);
            } catch (\Throwable $e) {
                //刷新失败不影响放行
            }
        }
        return $hit;
    }

    /**
     * 记一次对接下单被拒。同一来源在 DENY_LOG_WINDOW 内只记一条，会员在白名单页能看到并一键加入。
     */
    public static function recordDenied(User $user, string $ip): void
    {
        if ($ip === '') {
            return;
        }
        try {
            Schema::ensureUserLogTable();
            $logged = UserLog::query()
                ->where('user_id', (int)$user->id)
                ->where('action', self::LOG_DENIED)
                ->where('create_ip', $ip)
                ->where('create_time', '>=', date('Y-m-d H:i:s', time() - self::DENY_LOG_WINDOW))
                ->exists();
            if ($logged) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }
        UserLog::write($user, self::LOG_DENIED, '对接接口拒绝了一次余额下单：来源 IP 不在白名单', 1);
    }

    /**
     * 新增前的校验：规范化写法、去重、数量上限。放在动态码校验之前，填错不用白白输一次码。
     * @throws JSONException
     */
    public static function prepare(int $userId, string $raw): string
    {
        $ip = self::normalize($raw);
        Schema::ensureUserIpWhitelistTable();
        $entries = UserIpWhitelist::query()->where('user_id', $userId)->pluck('ip')->all();
        if (in_array($ip, $entries, true)) {
            throw new JSONException("该 IP 已在白名单中");
        }
        if (count($entries) >= self::LIMIT) {
            throw new JSONException("白名单最多 " . self::LIMIT . " 条，请先移除不用的");
        }
        return $ip;
    }

    /**
     * 登记一条白名单（调用方须已完成 prepare() 与身份校验）。
     * @throws JSONException
     */
    public static function add(int $userId, string $ip, string $note): UserIpWhitelist
    {
        $entry = new UserIpWhitelist();
        $entry->user_id = $userId;
        $entry->ip = $ip;
        $entry->note = self::cleanNote($note);
        $entry->create_time = Date::current();
        try {
            $entry->save();
        } catch (\Illuminate\Database\QueryException $e) {
            //并发重复提交撞上 (user_id, ip) 唯一键
            throw new JSONException("该 IP 已在白名单中");
        }
        return $entry;
    }

    /**
     * 移除一条白名单（不需要再验证：会话被盗者本就能在网页上直接用余额，开了资金验证时清空后是全部拒绝）。
     * @throws JSONException
     */
    public static function remove(int $userId, int $id): UserIpWhitelist
    {
        Schema::ensureUserIpWhitelistTable();
        $entry = UserIpWhitelist::query()->where('id', $id)->where('user_id', $userId)->first();
        if (!$entry) {
            throw new JSONException("该条目不存在或已移除");
        }
        $entry->delete();
        return $entry;
    }

    /**
     * 已登记条数（两步验证页的入口提示用）。
     */
    public static function count(int $userId): int
    {
        Schema::ensureUserIpWhitelistTable();
        return UserIpWhitelist::query()->where('user_id', $userId)->count();
    }

    /**
     * 白名单面板的数据：清单 + 最近被拒、且尚未被清单覆盖的来源。
     */
    public static function overview(int $userId): array
    {
        Schema::ensureUserIpWhitelistTable();
        $entries = UserIpWhitelist::query()->where('user_id', $userId)->orderBy('id')->get();

        $list = [];
        foreach ($entries as $entry) {
            $used = (string)($entry->last_used_time ?? '');
            $list[] = [
                'id' => (int)$entry->id,
                'ip' => (string)$entry->ip,
                'note' => (string)$entry->note,
                'create_time' => (string)$entry->create_time,
                'last_used_time' => $used,
                'last_used_relative' => $used !== '' ? Date::sauce($used) : '',
            ];
        }

        return [
            'list' => $list,
            'limit' => self::LIMIT,
            'denied' => self::recentDenied($userId, array_column($list, 'ip')),
        ];
    }

    /**
     * @param string[] $entries
     */
    private static function recentDenied(int $userId, array $entries): array
    {
        try {
            Schema::ensureUserLogTable();
            $rows = UserLog::query()
                ->where('user_id', $userId)
                ->where('action', self::LOG_DENIED)
                ->where('create_time', '>=', date('Y-m-d H:i:s', time() - self::DENIED_LOOKBACK_DAYS * 86400))
                ->orderByDesc('id')
                ->limit(200)
                ->get(['create_ip', 'create_time']);
        } catch (\Throwable $e) {
            return [];
        }

        $denied = [];
        foreach ($rows as $row) {
            $ip = (string)$row->create_ip;
            if ($ip === '' || isset($denied[$ip])) {
                continue;
            }
            foreach ($entries as $entry) {
                if (self::covers((string)$entry, $ip)) {
                    continue 2;
                }
            }
            $denied[$ip] = [
                'ip' => $ip,
                'time' => (string)$row->create_time,
                'relative' => Date::sauce((string)$row->create_time),
            ];
            if (count($denied) >= self::DENIED_SHOW) {
                break;
            }
        }
        return array_values($denied);
    }

    private static function cleanNote(string $note): string
    {
        $note = str_replace(['<', '>'], '', $note);
        $note = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $note);
        $note = trim((string)preg_replace('/\s+/u', ' ', $note));
        return mb_substr($note, 0, 32);
    }
}
