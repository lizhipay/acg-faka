<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Config;

final class Csp
{
    /** nonce 密钥在 config 表里的键名（NEVER_CACHE 通道，只存库） */
    public const SECRET_CONFIG = 'csp_nonce_secret';

    public const MODE_CONFIG = 'csp_mode';

    public const REPORT_PATH = '/csp/report';

    private const STORE = BASE_PATH . '/runtime/csp/violations.json';

    private const MAX_GROUPS = 400;

    private const MAX_BODY_BYTES = 32768;

    private const CLIENT_COOKIE = 'ACG_CSP';

    private static ?string $nonce = null;

    private static ?string $modeCache = null;

    public static function enabled(): bool
    {
        return self::mode() !== 'off';
    }

    public static function enforcing(): bool
    {
        return self::mode() === 'enforce';
    }

    public static function mode(): string
    {
        if (self::$modeCache !== null) {
            return self::$modeCache;
        }
        //只读缓存：键不存在就走默认值。Config::get() 对不存在的键会每次都拿排他锁再查库，
        //而这里每个请求都要跑；老站点升级前库里没有这个键，等于每请求一次网络锁。
        try {
            $v = (string)(Config::cached(self::MODE_CONFIG) ?? '');
        } catch (\Throwable) {
            return self::$modeCache = 'report';
        }
        return self::$modeCache = in_array($v, ['off', 'report', 'enforce'], true) ? $v : 'report';
    }

    public static function resetCache(): void
    {
        self::$extraCache = null;
        self::$modeCache = null;
        self::$nonce = null;
    }

    public static function header(): string
    {
        return self::enforcing() ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
    }

    public static function nonce(): string
    {
        if (self::$nonce !== null) {
            return self::$nonce;
        }

        $secret = self::secret();

        $raw = hash_hmac('sha256', self::clientKey(), $secret, true);
        return self::$nonce = rtrim(strtr(base64_encode(substr($raw, 0, 16)), '+/', '-_'), '=');
    }

    /**
     * nonce 的 HMAC 密钥。
     *
     * 用一把专门的随机密钥（安装 / 升级时生成，存 config 表的 csp_nonce_secret），
     * 走 Config::secret() 的 NEVER_CACHE 通道：不进 runtime/config、不进模板变量，
     * 每个请求只做一次唯一索引查询、不加任何文件锁（self::$nonce 会把结果记住）。
     *
     * 不能用商店的 app_key：那是登录应用商店后签发的凭证，重新登录就会换，换了之后
     * 已打开页面的 pjax 片段带的 nonce 和首屏的 CSP 头对不上，强制模式下整段脚本被拦；
     * 未绑定商店时它还是空的。也不能用 kernel/Install/Lock：安装程序写的是空文件。
     *
     * 密钥缺失（手工覆盖文件升级、没跑 update.php）或数据库暂时不可用时，退回由数据库
     * 连接信息派生的稳定值。绝不在请求里随机生成——nonce 必须对同一客户端稳定。
     */
    private static function secret(): string
    {
        if (file_exists(BASE_PATH . '/kernel/Install/Lock')) {
            try {
                $stored = base64_decode(trim(Config::secret(self::SECRET_CONFIG)), true);
                if ($stored !== false && strlen($stored) === 32) {
                    return $stored;
                }
            } catch (\Throwable) {
            }
        }

        try {
            $db = config('database');
            return hash('sha256', 'acg-csp-nonce|' . implode('|', [
                (string)($db['host'] ?? ''), (string)($db['database'] ?? ''),
                (string)($db['username'] ?? ''), (string)($db['password'] ?? ''),
                (string)($db['prefix'] ?? ''),
            ]), true);
        } catch (\Throwable) {
            return 'acg';
        }
    }

    private static function clientKey(): string
    {
        $existing = (string)($_COOKIE[self::CLIENT_COOKIE] ?? '');
        if (preg_match('/^[A-Za-z0-9]{24,64}$/', $existing)) {
            return $existing;
        }

        $fresh = bin2hex(random_bytes(16));
        $_COOKIE[self::CLIENT_COOKIE] = $fresh;
        if (!headers_sent()) {
            setcookie(self::CLIENT_COOKIE, $fresh, [
                'expires' => time() + 31536000,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off'),
            ]);
        }
        return $fresh;
    }

    public static function injectNonce(string $html): string
    {
        if ($html === '') {
            return $html;
        }
        $nonce = self::nonce();
        $html = (string)preg_replace(
            '#<script\b(?![^>]*\bnonce=)#i',
            '<script nonce="' . $nonce . '"',
            $html
        );

        //占位用的 href="javascript:void(0)" / "javascript:;" 本身什么都不做（真正的点击行为在 JS 监听里），
        //但强制模式下每次点击都会被拦并上报一条违规，把统计冲成噪音。这里统一换成惰性锚点，
        //由 csp-bind.js 阻止其默认跳转 —— 功能不变，统计干净。
        $html = (string)preg_replace(
            '#\shref\s*=\s*(["\'])javascript:\s*(?:void\s*\(\s*0\s*\)|)\s*;?\s*\1#i',
            ' href="#" data-acg-noop=""',
            $html
        );

        if (stripos($html, 'csp-bind.js') === false && stripos($html, '</head>') !== false) {
            //版本号跟文件内容走：只挂 APP_VERSION 的话，改了运行时而没发版，浏览器会一直用旧缓存
            $rev = (string)@filemtime(BASE_PATH . '/assets/common/js/csp-bind.js');
            $tag = '<script nonce="' . $nonce . '" src="/assets/common/js/csp-bind.js?v=' . APP_VERSION . '.' . $rev . '"></script>';
            $html = (string)preg_replace('#</head>#i', $tag . '</head>', $html, 1);
        }

        return $html;
    }

    /**
     * 插件可以往这几条指令里追加外部源。其余指令（default-src / object-src /
     * base-uri / form-action / frame-ancestors）是兜底防线，不开放。
     */
    private const EXTENSIBLE = [
        'script-src', 'style-src', 'font-src', 'img-src',
        'connect-src', 'frame-src', 'media-src', 'worker-src',
    ];

    /**
     * 必须是带主机名的源：scheme 可省，可用 *. 前缀，可带端口和路径。
     * 因为要求至少有一个点，'unsafe-inline' 这类带引号的关键字、裸 * 和裸协议
     * （https: 会放行全网脚本）都进不来。
     */
    private const SOURCE_PATTERN = '#^(?:https?://)?(?:\*\.)?[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+(?::\d{1,5})?(?:/[^\s;,\'"]*)?$#i';

    private static ?array $extraCache = null;

    /**
     * 收集插件声明的放行域名。任何一步出错都退回空清单——策略头绝不能因为
     * 某个插件写错而发不出去。
     *
     * @return array<string, string[]>
     */
    private static function extraSources(): array
    {
        if (self::$extraCache !== null) {
            return self::$extraCache;
        }

        $collected = [];
        try {
            $sources = array_fill_keys(self::EXTENSIBLE, []);
            hook(\App\Consts\Hook::CSP_SOURCE_ALLOW, $sources);

            foreach (self::EXTENSIBLE as $directive) {
                $list = $sources[$directive] ?? null;
                if (!is_array($list)) {
                    continue;
                }
                foreach ($list as $source) {
                    if (!is_string($source)) {
                        continue;
                    }
                    $source = trim($source);
                    if ($source === '' || !preg_match(self::SOURCE_PATTERN, $source)) {
                        continue;
                    }
                    $collected[$directive][$source] = true;
                }
            }
        } catch (\Throwable) {
            return self::$extraCache = [];
        }

        return self::$extraCache = array_map('array_keys', $collected);
    }

    public static function policy(): string
    {
        $extra = self::extraSources();
        $directive = static fn(string $name, string $value): string => trim(
            $name . ' ' . $value . ' ' . implode(' ', $extra[$name] ?? [])
        );

        return implode('; ', [
            "default-src 'self'",
            $directive('script-src', "'self' 'unsafe-eval' 'nonce-" . self::nonce() . "'"),
            $directive('style-src', "'self' 'unsafe-inline' https://fonts.googleapis.com"),
            $directive('font-src', "'self' data: https://fonts.gstatic.com"),
            //商户填的 QQ 头像等外部图源常是 http 明文；图片没有脚本能力，放行 http 不降低防护
            $directive('img-src', "'self' data: blob: https: http:"),
            $directive('media-src', "'self' data: blob: https:"),
            $directive('connect-src', "'self' https: wss: data: blob:"),
            $directive('frame-src', "'self' https:"),
            //代码编辑器（ACE）用 blob: URL 起 Web Worker 做语法校验。不单独写这条，
            //浏览器会回退到 script-src，而那里没有 blob:，Worker 直接被拦、编辑器功能失效。
            //worker-src 本来就在 EXTENSIBLE 名单里，但之前漏了输出，插件声明也会被静默丢掉。
            $directive('worker-src', "'self' blob:"),
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            'report-uri ' . self::REPORT_PATH,
        ]);
    }

    public static function record(array $report): void
    {
        $directive = self::pick($report, ['effective-directive', 'violated-directive', 'effectiveDirective']);
        $blocked = self::pick($report, ['blocked-uri', 'blockedURL', 'blockedURI']);
        $document = self::pick($report, ['document-uri', 'documentURL']);
        $source = self::pick($report, ['source-file', 'sourceFile']);
        $line = self::pick($report, ['line-number', 'lineNumber']);
        $sample = self::pick($report, ['script-sample', 'sample']);

        if ($directive === '' && $blocked === '') {
            return;
        }

        $directive = self::trim(explode(' ', $directive)[0], 48);
        $blocked = self::trim($blocked, 160);
        $document = self::trim(self::routeOf($document), 160);
        $source = self::trim($source, 160);
        $sample = self::trim($sample, 120);

        $key = md5($directive . '|' . $blocked . '|' . $source . '|' . $line . '|' . $document);

        self::withStore(function (array $store) use ($key, $directive, $blocked, $document, $source, $line, $sample): array {
            if (!isset($store[$key])) {
                if (count($store) >= self::MAX_GROUPS) {
                    return $store;
                }
                $store[$key] = [
                    'directive' => $directive,
                    'blocked' => $blocked,
                    'document' => $document,
                    'source' => $source,
                    'line' => $line,
                    'sample' => $sample,
                    'count' => 0,
                    'first' => time(),
                ];
            }
            $store[$key]['count']++;
            $store[$key]['last'] = time();
            return $store;
        });
    }

    public static function violations(int $limit = 100): array
    {
        $store = self::read();
        uasort($store, static fn(array $a, array $b): int => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));
        return array_slice(array_values($store), 0, max(1, $limit));
    }

    public static function summary(): array
    {
        $store = self::read();
        $total = 0;
        $byDirective = [];
        foreach ($store as $row) {
            $n = (int)($row['count'] ?? 0);
            $total += $n;
            $d = (string)($row['directive'] ?? '-');
            $byDirective[$d] = ($byDirective[$d] ?? 0) + $n;
        }
        arsort($byDirective);
        return ['groups' => count($store), 'total' => $total, 'directives' => $byDirective];
    }

    public static function clear(): void
    {
        @unlink(self::STORE);
    }

    public static function maxBodyBytes(): int
    {
        return self::MAX_BODY_BYTES;
    }

    private static function read(): array
    {
        if (!is_file(self::STORE)) {
            return [];
        }
        $raw = @file_get_contents(self::STORE);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private static function withStore(callable $mutator): void
    {
        $dir = dirname(self::STORE);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $handle = @fopen(self::STORE, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            $raw = stream_get_contents($handle);
            $store = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            $store = is_array($store) ? $store : [];
            $store = $mutator($store);
            $encoded = json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, $encoded);
                fflush($handle);
            }
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private static function pick(array $report, array $keys): string
    {
        foreach ($keys as $k) {
            if (isset($report[$k]) && is_scalar($report[$k])) {
                return (string)$report[$k];
            }
        }
        return '';
    }

    private static function routeOf(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $path = parse_url($url, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : $url;
    }

    private static function trim(string $value, int $max): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
