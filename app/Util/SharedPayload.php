<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Shared;
use Kernel\Util\Log;

/**
 * 出站商品载荷的「转售身份」防泄露层。
 *
 * 本站既可能是别人的下游（从上游店铺对接商品转售），又可能是别人的上游
 * （下级用店铺共享对接我们）。协议出口一旦把「这件商品是转售来的」以及
 * 「从谁那里转售」透出去，下级就能绕过我们直连我们的上游。核心早期的出口
 * 是「模型整行 toArray() 直接出站」，于是 shared_id / shared_code /
 * factory_price / 上游域名 全部随商品一起发给了下游，且公开的商品详情
 * 接口（免登录）同样带着这些列。
 *
 * 这里把所有出口收敛到一处，三件事：
 *
 * 1. **白名单出场，不是黑名单剔除**。核心或第三方面板以后往 commodity 表
 *    加列（本机就见过 dock_* / asyn_request_url / humkt_goods_id 这类外来
 *    列），新列默认不出站，不需要有人记得来这里补一条黑名单。
 * 2. **定价配置按段裁剪**。config 里的 category_cost / sku_cost 是上游原价
 *    （= 我们的进货价），shared_mapping 是上游的 SKU 主键，*_factory 是我们
 *    自己那一层的拿货价快照——都不能出站。种类拿货价由出口按请求方身份现算
 *    后再塞回去（见 Shared\Commodity::item/inventory），所以这里先清干净是
 *    正确顺序。
 * 3. **文本里的上游地址一律抹掉**。最隐蔽的一条：商品介绍是 HTML，接入货源
 *    时没开「远端图片本地化」的话，里面的 <img src> 就是上游域名的直链，
 *    封面同理。域名表来自 App\Model\Shared.domain，只认自己配过的上游，
 *    不会误伤别的外链。
 *
 * 出站文本还包括**上游返回的报错**：`Bind\Shared::post()` 原样透传对方的 msg，
 * 而这条 msg 会一路冒泡到公开的商品详情页和下游的接口响应里。
 */
final class SharedPayload
{
    /** 上游地址被抹掉后，URL 位置上的替身（保持 <img src> 合法，退化成站点图标） */
    public const PLACEHOLDER_URL = '/favicon.ico';

    /** 上游地址被抹掉后，纯文本位置上的替身 */
    public const PLACEHOLDER_HOST = '***';

    /**
     * 允许出站的商品字段。口径 = 下游真正需要用来建档与跟价的那些列
     * （对照 Admin\Api\Store::remoteItem() 与自动货源接入的指纹字段）。
     */
    public const COMMODITY_FIELDS = [
        'id', 'category_id', 'name', 'description', 'cover',
        'price', 'user_price', 'status', 'code', 'sort',
        'delivery_way', 'contact_type', 'password_status', 'coupon',
        'seckill_status', 'seckill_start_time', 'seckill_end_time',
        'draft_status', 'draft_premium', 'inventory_hidden',
        'only_user', 'purchase_count', 'widget', 'minimum', 'maximum',
        'config', 'stock', 'tags',
    ];

    /** 允许出站的分类字段（owner / create_time / user_level_config 是本站内务） */
    public const CATEGORY_FIELDS = ['id', 'name', 'sort', 'icon', 'status', 'pid'];

    /**
     * 明确禁止出站的商品键。白名单已经足够，这份清单是给测试当断言用的——
     * 谁把某个键加进 COMMODITY_FIELDS，回归立刻变红。
     */
    public const FORBIDDEN_KEYS = [
        'shared_id', 'shared_code', 'shared_premium', 'shared_premium_type',
        'shared_premium_template', 'shared_stock', 'shared_sync',
        'shared_amount_sync', 'shared_config_sync', 'inventory_sync',
        'factory_price', 'level_price', 'level_disable',
        'owner', 'create_time', 'api_status', 'hide', 'recommend',
        'leave_message', 'delivery_message', 'delivery_auto_mode', 'send_email',
    ];

    /**
     * 商品详情（Service\Shop::getItem 的出参）必须剔除的键。
     *
     * 详情这条路同时供**免登录的前台商品接口**和**店铺共享的 item 接口**使用，
     * 两边都不该看到转售身份与定价内幕。这里比 FORBIDDEN_KEYS 窄：`owner` 要留下
     * ——它是商家展示信息（用户名/头像），前台在用；`code` 也要留下，下游按它对接。
     */
    public const DETAIL_STRIP_KEYS = [
        'shared_id', 'shared_code', 'shared_premium', 'shared_premium_type',
        'shared_premium_template', 'shared_stock', 'shared_sync',
        'shared_amount_sync', 'shared_config_sync', 'inventory_sync',
        'factory_price', 'level_price', 'level_disable',
    ];

    /**
     * 不能出站的定价配置段。
     * - category_cost / sku_cost：上游原价，syncRemoteItem 落库用来算成本；
     * - shared_mapping：上游的 SKU 主键（type=1 平台对接）；
     * - *_factory：本站自己这一层的拿货价快照，陈旧且属于内部口径。
     */
    public const FORBIDDEN_CONFIG_SECTIONS = [
        'category_cost', 'sku_cost', 'shared_mapping',
        'category_factory', 'wholesale_factory', 'category_wholesale_factory', 'sku_factory',
    ];

    /** @var array{0:float,1:array<int,string>}|null 上游域名表的短 TTL 缓存 */
    private static ?array $hostCache = null;

    /** @var array<int,string>|null 测试注入的域名表（非 null 时完全接管） */
    private static ?array $hostOverride = null;

    /** 上游域名表的缓存寿命（秒）。常驻守护里也要能在一分钟内看到新加的店铺。 */
    private const HOST_TTL = 60;

    /**
     * 商品行按白名单出场，并顺带清洗 config / description / cover。
     *
     * @param array $row 商品模型的数组形态
     */
    public static function commodity(array $row): array
    {
        $out = [];
        foreach (self::COMMODITY_FIELDS as $field) {
            if (array_key_exists($field, $row)) {
                $out[$field] = $row[$field];
            }
        }

        if (array_key_exists('config', $out)) {
            $out['config'] = self::config($out['config']);
        }
        if (isset($out['description']) && is_string($out['description'])) {
            $out['description'] = self::scrubText($out['description']);
        }
        if (isset($out['cover']) && is_string($out['cover'])) {
            $out['cover'] = self::scrubUrl($out['cover']);
        }

        return $out;
    }

    /**
     * 商品详情出参的剔除式清洗。详情的字段是 getItem() 手写 select 出来的，
     * 已经是一份白名单，这里只需要把其中的转售身份与定价内幕摘掉。
     */
    public static function detail(array $array): array
    {
        foreach (self::DETAIL_STRIP_KEYS as $key) {
            unset($array[$key]);
        }
        if (array_key_exists('config', $array)) {
            $array['config'] = self::config($array['config']);
        }
        if (isset($array['description']) && is_string($array['description'])) {
            $array['description'] = self::scrubText($array['description']);
        }
        if (isset($array['cover']) && is_string($array['cover'])) {
            $array['cover'] = self::scrubUrl($array['cover']);
        }
        return $array;
    }

    /**
     * 分类行按白名单出场。children 由调用方自己组装（避免这里递归吃掉大树）。
     */
    public static function category(array $row): array
    {
        $out = [];
        foreach (self::CATEGORY_FIELDS as $field) {
            if (array_key_exists($field, $row)) {
                $out[$field] = $row[$field];
            }
        }
        if (isset($out['icon']) && is_string($out['icon'])) {
            $out['icon'] = self::scrubUrl($out['icon']);
        }
        return $out;
    }

    /**
     * 裁掉配置里的成本段。入参可以是 ini 文本，也可以是已解析的数组，
     * 出参与入参同型——两种形态在协议里都出现过。
     */
    public static function config(mixed $config): mixed
    {
        if (is_array($config)) {
            return self::configArray($config);
        }
        if (!is_string($config) || $config === '') {
            return $config;
        }
        //绝大多数商品的配置里根本没有成本段：先用一次字符串扫描短路，既省掉整站商品
        //列表上的逐行 ini 解析，也避免无谓的 parse→serialize 往返改动原文格式。
        $hit = false;
        foreach (self::FORBIDDEN_CONFIG_SECTIONS as $section) {
            if (stripos($config, $section) !== false) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            return $config;
        }
        try {
            $parsed = Ini::toArray($config);
        } catch (\Throwable $e) {
            //解析不了的脏配置：宁可整段不出站，也不能把可能含成本段的原文发出去
            return '';
        }
        $clean = self::configArray($parsed);
        if ($clean === $parsed) {
            return $config;
        }
        return Ini::toConfig($clean);
    }

    /**
     * @param array $config
     * @return array
     */
    public static function configArray(array $config): array
    {
        foreach (self::FORBIDDEN_CONFIG_SECTIONS as $section) {
            unset($config[$section]);
        }
        return $config;
    }

    /**
     * 抹掉文本里指向上游的地址。只认已配置的上游域名，其它外链原样保留。
     */
    public static function scrubText(string $text): string
    {
        $hosts = self::upstreamHosts();
        if ($hosts === [] || $text === '') {
            return $text;
        }

        //绝对地址与协议相对地址：整条换成站点图标，图片不会变成裂图
        $text = (string)preg_replace_callback(
            '#(?:\bhttps?:)?//[^\s"\'<>()\[\]\\\\]+#i',
            static function (array $m) use ($hosts): string {
                return self::hostMatches(self::hostOf($m[0]), $hosts) ? self::PLACEHOLDER_URL : $m[0];
            },
            $text
        );

        //裸域名（"发货问题请联系 shop.example.com" 这种）
        foreach ($hosts as $host) {
            if ($host !== '' && stripos($text, $host) !== false) {
                $text = str_ireplace($host, self::PLACEHOLDER_HOST, $text);
            }
        }

        return $text;
    }

    /**
     * 单个地址：命中上游域名就退化成站点图标。
     */
    public static function scrubUrl(string $url, string $fallback = self::PLACEHOLDER_URL): string
    {
        if ($url === '') {
            return $url;
        }
        $hosts = self::upstreamHosts();
        if ($hosts === []) {
            return $url;
        }
        return self::hostMatches(self::hostOf($url), $hosts) ? $fallback : $url;
    }

    /**
     * 上游返回的报错文本。上游会在 msg 里写自己的站名、充值地址、商品编号，
     * 而这条 msg 会顺着 syncRemoteItem 冒泡到公开的商品详情、顺着下单冒泡到
     * 下游的接口响应。这里把地址类标识一律抹掉——**不只是已配置的上游域名**：
     * 上游完全可能在报错里写自己的另一个域名，那同样是身份。
     *
     * 完整原文写日志，站长在后台日志里照样查得到。
     */
    public static function scrubMessage(?string $message): string
    {
        $message = trim((string)$message);
        if ($message === '') {
            return '';
        }

        $clean = (string)preg_replace('#(?:\bhttps?:)?//[^\s"\'<>()\[\]\\\\]+#i', self::PLACEHOLDER_HOST, $message);
        //裸域名与 IP（含端口）
        $clean = (string)preg_replace(
            '#\b(?:\d{1,3}\.){3}\d{1,3}(?::\d{1,5})?\b#',
            self::PLACEHOLDER_HOST,
            $clean
        );
        $clean = (string)preg_replace(
            '#\b(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,24})(?::\d{1,5})?\b#i',
            self::PLACEHOLDER_HOST,
            $clean
        );

        $clean = trim((string)preg_replace('/\s+/u', ' ', $clean));
        if ($clean !== $message) {
            self::log("上游报错原文（出站前已脱敏）：{$message}");
        }

        return $clean === '' ? '' : mb_substr($clean, 0, 200, 'UTF-8');
    }

    /**
     * 上游报错的出站口径。站长自己在后台操作时（管理端会话）保留原文，
     * 排障需要它；其余场合——公开的商品详情页、下游的接口响应——一律脱敏，
     * 原文进日志。
     */
    public static function upstreamError(?string $raw): string
    {
        $raw = trim(strip_tags((string)$raw));
        if ($raw === '') {
            return '';
        }
        return self::trustedAudience() ? $raw : self::scrubMessage($raw);
    }

    /**
     * 面向站长的详细文案 vs 面向外部的通用文案。
     * 管理端会话里给详细的（站长需要它才知道去改哪儿），其余场合只给通用文案，
     * 详细原文进日志。
     */
    public static function guardMessage(string $detail, string $public): string
    {
        if (self::trustedAudience()) {
            return $detail;
        }
        self::log($detail);
        return $public;
    }

    /**
     * 这条报错会不会被外人看到。
     *
     * 两种「不会」：①管理端会话——站长自己在后台操作，详细原文正是他要的；
     * ②没有 HTTP 请求上下文的 CLI——常驻守护（自动货源接入 / 事件广播中心）与
     * 命令行脚本压根没有响应体，脱敏只会让站长自己的流水失去排障价值。
     * 判据用 REQUEST_METHOD 而不是只看 PHP_SAPI：协程 HTTP 服务器同样跑在 cli 下。
     */
    private static function trustedAudience(): bool
    {
        if (Context::get(\App\Consts\Manage::SESSION)) {
            return true;
        }
        return PHP_SAPI === 'cli' && empty($_SERVER['REQUEST_METHOD']);
    }

    /**
     * 已配置的上游域名（host，不含端口）。
     *
     * **本站自己的域名被排除在外**：开发环境常见「自己连自己」的回环上游，
     * 把本站域名也当成上游会把自己的图片地址一并抹掉。
     *
     * @return array<int,string>
     */
    public static function upstreamHosts(): array
    {
        if (self::$hostOverride !== null) {
            return self::$hostOverride;
        }

        $now = microtime(true);
        if (self::$hostCache !== null && ($now - self::$hostCache[0]) < self::HOST_TTL) {
            return self::$hostCache[1];
        }

        $hosts = [];
        try {
            $self = self::hostOf((string)($_SERVER['HTTP_HOST'] ?? ''));
            foreach (Shared::query()->get(['domain']) as $row) {
                $host = self::hostOf((string)$row->domain);
                if ($host !== '' && $host !== $self) {
                    $hosts[$host] = true;
                }
            }
        } catch (\Throwable $e) {
            //取不到就返回上一次的结果，绝不因为一次数据库抖动放开防线
            return self::$hostCache[1] ?? [];
        }

        self::$hostCache = [$now, array_keys($hosts)];
        return self::$hostCache[1];
    }

    /**
     * 测试注入用；传 null 恢复读库。
     * @param array<int,string>|null $hosts
     */
    public static function setUpstreamHosts(?array $hosts): void
    {
        self::$hostOverride = $hosts === null ? null : array_values(array_filter(array_map(
            static fn($h) => self::hostOf((string)$h),
            $hosts
        )));
        self::$hostCache = null;
    }

    /**
     * 从任意地址片段里取出 host（小写、去端口、去用户信息）。
     */
    public static function hostOf(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
            $value = (str_starts_with($value, '//') ? 'http:' : 'http://') . ltrim($value, '/');
        }
        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }
        return strtolower(trim($host, '[]'));
    }

    /**
     * host 是否命中上游域名（同域或其子域）。
     * @param array<int,string> $hosts
     */
    public static function hostMatches(string $host, array $hosts): bool
    {
        if ($host === '') {
            return false;
        }
        foreach ($hosts as $upstream) {
            if ($upstream !== '' && ($host === $upstream || str_ends_with($host, '.' . $upstream))) {
                return true;
            }
        }
        return false;
    }

    private static function log(string $message): void
    {
        try {
            Log::inst()->error($message);
        } catch (\Throwable $e) {
            //日志不可用不能拖垮出站清洗
        }
    }
}
