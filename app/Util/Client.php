<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Config;
use JetBrains\PhpStorm\NoReturn;
use Kernel\Util\View;

/**
 * Class Client
 * @package App\Util
 */
class Client
{
    private const HEADERS = [
        'REMOTE_ADDR',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'HTTP_CF_CONNECTING_IP'
    ];

    private const CHAIN_HEADER_MODES = [2, 4, 5, 6, 7];
    public const MODE_CONFIG = 'ip_get_mode';

    /**
     * 受信代理清单的存放位置。
     *
     * 3.7.2 之前只写在 `runtime/trusted_proxies` 里。那是个所有人都当缓存看待、
     * 部署/升级/重建容器随手就清掉的目录——清掉之后 isTrustedProxy() 恒为 false，
     * getAddress() 会无条件退回 REMOTE_ADDR，站长配的「IP 获取方式」形同虚设，
     * 订单 IP 全变成反代地址（issue #928）。安全配置必须落在配置表里。
     * 老文件保留为读取兜底与一次性迁移来源。
     */
    public const TRUSTED_PROXY_CONFIG = 'trusted_proxy_ips';
    private const LEGACY_TRUSTED_PROXY_FILE = BASE_PATH . '/runtime/trusted_proxies';

    private const LEGACY_MODE_FILE = BASE_PATH . '/runtime/mode';

    private const MAX_TRUSTED_PROXY_CONFIG_LENGTH = 8192;
    private const MAX_TRUSTED_PROXY_ENTRIES = 256;
    private const MAX_PROXY_HEADER_LENGTH = 8192;
    private const MAX_PROXY_HEADER_ENTRIES = 64;

    /**
     * @var int|null
     */
    private static ?int $mode = null;

    /**
     * @var string|null
     */
    private static ?string $trustedProxyConfig = null;

    /**
     * @var array<int, string>|null
     */
    private static ?array $trustedProxyRanges = null;

    /**
     * @param int $mode
     * @return void
     */
    public static function setClientMode(int $mode): void
    {
        if ($mode < 0 || $mode >= count(self::HEADERS)) {
            throw new \InvalidArgumentException('客户端 IP 获取方式不正确');
        }
        Config::put(self::MODE_CONFIG, (string)$mode);
        self::$mode = $mode;
    }

    /**
     * @return void
     */
    public static function resetModeCache(): void
    {
        self::$mode = null;
    }

    /**
     * @return bool
     */
    public static function haveMode(): bool
    {
        if (!self::configReadable()) {
            return false;
        }

        try {
            if (Config::cached(self::MODE_CONFIG) !== null) {
                return true;
            }
        } catch (\Throwable) {
            return false;
        }

        return is_file(self::LEGACY_MODE_FILE);
    }

    /**
     * @return int
     */
    public static function getClientMode(): int
    {
        if (self::$mode !== null) {
            return self::$mode;
        }
        return self::$mode = self::resolveClientMode();
    }

    /**
     * 取值发生在 Request 构造期间——那时数据库连接还没建立，未安装的站点连库都没有。
     * 所以这里走只读缓存，拿不到就退回旧的落地文件，任何一步失败都当作默认值 0。
     *
     * @return int
     */
    private static function resolveClientMode(): int
    {
        if (!self::configReadable()) {
            return 0;
        }

        try {
            $value = Config::cached(self::MODE_CONFIG);
        } catch (\Throwable) {
            return 0;
        }

        if ($value === null) {
            return self::legacyClientMode();
        }

        return self::normalizeMode($value);
    }

    /**
     * @return bool
     */
    private static function configReadable(): bool
    {
        return is_file(BASE_PATH . '/kernel/Install/Lock');
    }

    /**
     * @return int
     */
    private static function legacyClientMode(): int
    {
        if (!is_file(self::LEGACY_MODE_FILE)) {
            return 0;
        }
        $value = @file_get_contents(self::LEGACY_MODE_FILE);
        return $value === false ? 0 : self::normalizeMode($value);
    }

    /**
     * @param string $value
     * @return int
     */
    private static function normalizeMode(string $value): int
    {
        $value = trim($value);
        if ($value === '' || !ctype_digit($value)) {
            return 0;
        }
        $mode = (int)$value;
        return $mode >= 0 && $mode < count(self::HEADERS) ? $mode : 0;
    }

    /**
     * Normalize and validate an administrator-supplied proxy allowlist.
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizeTrustedProxyConfig(string $config): string
    {
        if (strlen($config) > self::MAX_TRUSTED_PROXY_CONFIG_LENGTH) {
            throw new \InvalidArgumentException('受信代理清单过长');
        }

        $config = trim(str_replace(["\r\n", "\r"], "\n", $config));
        if ($config === '') {
            return '';
        }

        $entries = preg_split('/[\s,;]+/u', $config, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($entries) || count($entries) > self::MAX_TRUSTED_PROXY_ENTRIES) {
            throw new \InvalidArgumentException('受信代理最多允许 256 个 IP 或 CIDR');
        }

        $normalized = [];
        foreach ($entries as $entry) {
            $parts = explode('/', $entry, 2);
            $ip = self::normalizeIp($parts[0]);
            if ($ip === null) {
                throw new \InvalidArgumentException("受信代理格式不正确：{$entry}");
            }

            if (isset($parts[1])) {
                if (!preg_match('/^\d{1,3}$/D', $parts[1])) {
                    throw new \InvalidArgumentException("受信代理 CIDR 格式不正确：{$entry}");
                }
                $prefix = (int)$parts[1];
                $maxPrefix = str_contains($ip, ':') ? 128 : 32;
                if ($prefix < 1 || $prefix > $maxPrefix) {
                    throw new \InvalidArgumentException("受信代理 CIDR 范围不正确：{$entry}");
                }
                $ip .= '/' . $prefix;
            }

            $normalized[$ip] = true;
        }

        return implode("\n", array_keys($normalized));
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public static function setTrustedProxyConfig(string $config): void
    {
        $config = self::normalizeTrustedProxyConfig($config);
        Config::put(self::TRUSTED_PROXY_CONFIG, $config);
        self::cacheTrustedProxyConfig($config);
        //配置表已经是权威来源，老文件留着只会在 runtime 被清空时给出错误答案
        @unlink(self::LEGACY_TRUSTED_PROXY_FILE);
    }

    /**
     * 清掉进程内的受信代理缓存。配置在别处（批量保存）被改写后调用。
     */
    public static function resetTrustedProxyCache(): void
    {
        self::$trustedProxyConfig = null;
        self::$trustedProxyRanges = null;
    }

    public static function getTrustedProxyConfig(): string
    {
        if (self::$trustedProxyConfig !== null) {
            return self::$trustedProxyConfig;
        }

        $config = null;
        if (self::configReadable()) {
            try {
                $config = Config::cached(self::TRUSTED_PROXY_CONFIG);
            } catch (\Throwable) {
                $config = null;
            }
        }

        if ($config === null) {
            //老站的清单还在 runtime 文件里：读它，并趁这次把它搬进配置表。
            //搬成功就删文件，所以整个站生命周期里最多发生一次。
            $config = self::legacyTrustedProxyConfig();
            if ($config !== '') {
                self::migrateTrustedProxyConfig($config);
            }
        }

        try {
            return self::cacheTrustedProxyConfig(self::normalizeTrustedProxyConfig($config));
        } catch (\InvalidArgumentException) {
            // A manually corrupted allowlist must fail closed.
            return self::cacheTrustedProxyConfig('');
        }
    }

    private static function cacheTrustedProxyConfig(string $config): string
    {
        self::$trustedProxyConfig = $config;
        self::$trustedProxyRanges = $config === '' ? [] : explode("\n", $config);
        return $config;
    }

    private static function legacyTrustedProxyConfig(): string
    {
        if (!is_file(self::LEGACY_TRUSTED_PROXY_FILE)) {
            return '';
        }
        $config = @file_get_contents(self::LEGACY_TRUSTED_PROXY_FILE);
        return $config === false ? '' : $config;
    }

    /**
     * 一次性迁移：把落地文件里的清单写进配置表并删掉文件。
     * 失败（数据库还没连上、没有写权限）就当无事发生——这次仍然按文件里的值放行，
     * 下一个请求再试。
     */
    private static function migrateTrustedProxyConfig(string $config): void
    {
        try {
            Config::put(self::TRUSTED_PROXY_CONFIG, self::normalizeTrustedProxyConfig($config));
            @unlink(self::LEGACY_TRUSTED_PROXY_FILE);
        } catch (\Throwable) {
            //保持原样，下次请求再迁
        }
    }

    private static function normalizeIp(string $value): ?string
    {
        $value = trim($value, " \t\n\r\0\x0B\"");
        if ($value === '' || strtolower($value) === 'unknown' || str_starts_with($value, '_')) {
            return null;
        }

        if (preg_match('/^\[([0-9a-f:.]+)](?::\d{1,5})?$/iD', $value, $matches)) {
            $value = $matches[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d{1,5}$/D', $value, $matches)) {
            $value = $matches[1];
        }

        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($value);
        if ($packed === false) {
            return null;
        }

        // Treat IPv4-mapped IPv6 addresses consistently with native IPv4.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }
        $normalized = inet_ntop($packed);
        return $normalized === false ? null : $normalized;
    }

    /**
     * @return array<int, string>
     */
    private static function headerIpCandidates(int $type): array
    {
        if (!isset(self::HEADERS[$type])) {
            return [];
        }

        $value = $_SERVER[self::HEADERS[$type]] ?? null;
        if (!is_scalar($value) || trim((string)$value) === '') {
            return [];
        }

        $value = (string)$value;
        if (strlen($value) > self::MAX_PROXY_HEADER_LENGTH) {
            return [];
        }
        $elements = explode(',', $value);
        if (count($elements) > self::MAX_PROXY_HEADER_ENTRIES) {
            return [];
        }

        $candidates = [];
        if ($type === 7) {
            foreach ($elements as $element) {
                foreach (explode(';', $element) as $parameter) {
                    $parts = explode('=', $parameter, 2);
                    if (count($parts) !== 2 || strtolower(trim($parts[0])) !== 'for') {
                        continue;
                    }
                    $ip = self::normalizeIp($parts[1]);
                    if ($ip !== null) {
                        $candidates[] = $ip;
                    }
                    break;
                }
            }
            return $candidates;
        }

        foreach ($elements as $candidate) {
            $candidate = preg_replace('/^\s*for\s*=\s*/i', '', $candidate);
            $ip = self::normalizeIp((string)$candidate);
            if ($ip !== null) {
                $candidates[] = $ip;
            }
        }
        return $candidates;
    }

    private static function ipMatchesRange(string $ip, string $range): bool
    {
        $parts = explode('/', $range, 2);
        $network = self::normalizeIp($parts[0]);
        if ($network === null) {
            return false;
        }

        $ipBinary = inet_pton($ip);
        $networkBinary = inet_pton($network);
        if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
            return false;
        }
        if (!isset($parts[1])) {
            return hash_equals($networkBinary, $ipBinary);
        }

        $prefix = (int)$parts[1];
        $wholeBytes = intdiv($prefix, 8);
        if ($wholeBytes > 0 && !hash_equals(
            substr($networkBinary, 0, $wholeBytes),
            substr($ipBinary, 0, $wholeBytes)
        )) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($networkBinary[$wholeBytes]) & $mask) === (ord($ipBinary[$wholeBytes]) & $mask);
    }

    private static function isTrustedProxy(string $ip): bool
    {
        if (self::$trustedProxyRanges === null) {
            self::getTrustedProxyConfig();
        }
        foreach (self::$trustedProxyRanges ?? [] as $range) {
            if (self::ipMatchesRange($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param int $type
     * @return string|null
     */
    public static function getIp(int $type): ?string
    {
        return self::headerIpCandidates($type)[0] ?? null;
    }

    /*
     * 获取客户端IP地址
     * @return string
     */
    public static function getAddress(): string
    {
        $remoteAddress = self::normalizeIp((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remoteAddress === null) {
            return '';
        }

        $mode = self::getClientMode();
        if ($mode === 0 || !self::isTrustedProxy($remoteAddress)) {
            return $remoteAddress;
        }

        $candidates = self::headerIpCandidates($mode);
        if ($candidates === []) {
            return $remoteAddress;
        }

        if (!in_array($mode, self::CHAIN_HEADER_MODES, true)) {
            // Single-address headers must not be accepted as an injected list.
            return count($candidates) === 1 ? $candidates[0] : $remoteAddress;
        }

        // Walk from the application back towards the client. Trusted hops are
        // skipped; the first untrusted address is the actual client address.
        for ($index = count($candidates) - 1; $index >= 0; $index--) {
            if (!self::isTrustedProxy($candidates[$index])) {
                return $candidates[$index];
            }
        }
        return $remoteAddress;
    }

    /**
     * @return string
     */
    public static function getUserAgent(): string
    {
        return (string)$_SERVER['HTTP_USER_AGENT'];
    }

    /**
     * 获取URL地址
     * @return string
     */
    public static function getUrl(): string
    {
        if (strtolower((string)$_SERVER["HTTPS"]) == "on") {
            $_SERVER['REQUEST_SCHEME'] = "https";
        } elseif (!isset($_SERVER['REQUEST_SCHEME'])) {
            $_SERVER['REQUEST_SCHEME'] = "http";
        }
        return $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'];
    }

    /**
     * @return string
     */
    public static function getDomain(): string
    {
        $host = explode(":", (string)$_SERVER['HTTP_HOST']);
        return (string)$host[0];
    }

    /**
     * 重定向浏览器地址
     * @param string $url
     * @param string $message
     * @param int $time
     * @throws \SmartyException
     */
    public static function redirect(string $url, string $message, int $time = 2): void
    {
        if ($time == 0) {
            header('location:' . $url);
        } else {
            header("refresh:{$time},url={$url}");
            //把目标地址与等待秒数一并给到视图：跳转本身由 refresh 头完成，
            //视图拿到这两个值才能让进度动画与真实等待时间同步，并提供"立即前往"
            echo View::render("404.html", ["msg" => $message, "url" => $url, "time" => $time]);
        }
        exit;
    }


    /**
     * 判断是否手机访问
     * @return bool
     */
    public static function isMobile(): bool
    {
        if (isset($_SERVER['HTTP_X_WAP_PROFILE'])) {
            return true;
        }
        if (isset($_SERVER['HTTP_VIA'])) {
            return (bool)stristr($_SERVER['HTTP_VIA'], "wap");
        }
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $clientkeywords = array('nokia', 'sony', 'ericsson', 'mot', 'samsung', 'htc', 'sgh', 'lg', 'sharp', 'sie-', 'philips', 'panasonic', 'alcatel', 'lenovo', 'iphone', 'ipod', 'blackberry', 'meizu', 'android', 'netfront', 'symbian', 'ucweb', 'windowsce', 'palm', 'operamini', 'operamobi', 'openwave', 'nexusone', 'cldc', 'midp', 'wap', 'mobile', 'MicroMessenger');
            if (preg_match("/(" . implode('|', $clientkeywords) . ")/i", strtolower($_SERVER['HTTP_USER_AGENT']))) {
                return true;
            }
        }
        if (isset ($_SERVER['HTTP_ACCEPT'])) {
            if ((str_contains($_SERVER['HTTP_ACCEPT'], 'vnd.wap.wml')) && (!str_contains($_SERVER['HTTP_ACCEPT'], 'text/html') || (strpos($_SERVER['HTTP_ACCEPT'], 'vnd.wap.wml') < strpos($_SERVER['HTTP_ACCEPT'], 'text/html')))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return bool
     */
    public static function isWeChat(): bool
    {
        return preg_match('/MicroMessenger/i', $_SERVER['HTTP_USER_AGENT']) === 1;
    }

    /**
     * @param string|null $userAgent
     * @return int
     */
    public static function getDeviceTypeByUa(?string $userAgent = null): int
    {
        $ua = strtolower($userAgent ?? $_SERVER['HTTP_USER_AGENT'] ?? '');
        return match (true) {
            str_contains($ua, 'ipad') => 3,
            str_contains($ua, 'macintosh') && str_contains($ua, 'mobile') => 3,
            str_contains($ua, 'iphone'),
            str_contains($ua, 'ipod') => 2,
            str_contains($ua, 'android') => 1,
            default => 0,
        };
    }
}
