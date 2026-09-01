<?php
declare (strict_types=1);

namespace Kernel\Util;

use App\Model\Config;
use App\Util\Client;
use Kernel\Context\Interface\Request;

class RequestLogger
{

    /**
     * 是否记录请求日志。
     *
     * 新键 request_log_enabled：1=记录、0=不记录，缺省视为记录。
     * 旧键 request_log 的含义是**相反**的（1=不记录），升级上来的站点若显式关过日志，
     * 这里按旧值还原他的选择，不会因为改名把日志悄悄打开。
     */
    public static function enabled(): bool
    {
        $now = (string)Config::get("request_log_enabled");
        if ($now !== "") {
            return $now === "1";
        }
        return (string)Config::get("request_log") !== "1";
    }

    /**
     * 日志文件所在目录。目录名按数据库密码哈希，换库即换目录（旧目录不会被误删）。
     */
    private static function baseDir(): string
    {
        $config = config("database");
        return rtrim(BASE_PATH, DIRECTORY_SEPARATOR) . '/runtime/request/' . md5($config['password']);
    }

    /**
     * 现存日志概况：文件数、总字节、最早与最晚日期。
     *
     * @return array{files:int, bytes:int, oldest:string, newest:string}
     */
    public static function summary(): array
    {
        $files = glob(self::baseDir() . '/*.log') ?: [];
        $bytes = 0;
        $dates = [];
        foreach ($files as $f) {
            $bytes += (int)@filesize($f);
            $dates[] = basename($f, '.log');
        }
        sort($dates);
        return [
            'files' => count($files),
            'bytes' => $bytes,
            'oldest' => $dates[0] ?? '',
            'newest' => $dates ? end($dates) : '',
        ];
    }

    /**
     * 清理日志。$days=0 删全部；否则只删「日期早于今天减 $days 天」的文件，当天的一律保留。
     *
     * 只按文件名里的日期判断、不看 mtime：日志是按天追加的，mtime 会被最后一次写入带偏。
     *
     * @return array{deleted:int, bytes:int}
     */
    public static function prune(int $days): array
    {
        $files = glob(self::baseDir() . '/*.log') ?: [];
        $cutoff = $days > 0 ? date('Y-m-d', strtotime("-{$days} days")) : null;

        $deleted = 0;
        $bytes = 0;
        foreach ($files as $f) {
            $date = basename($f, '.log');
            if ($cutoff !== null) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date >= $cutoff) {
                    continue;
                }
            }
            $size = (int)@filesize($f);
            if (@unlink($f)) {
                $deleted++;
                $bytes += $size;
            }
        }
        return ['deleted' => $deleted, 'bytes' => $bytes];
    }

    /**
     * 记录当前请求
     */
    public static function logCurrentRequest(Request $request): void
    {
        try {
            if (!file_exists(BASE_PATH . '/kernel/Install/Lock')) {
                return;
            }

            if (!self::enabled()) {
                return;
            }

            //没有密钥就不落盘。宁可少一条日志，也不能退回明文写一行敏感请求上去。
            if (!\App\Util\RequestLogCrypto::available()) {
                return;
            }

            $baseDir = self::baseDir();
            $logFile = $baseDir . '/' . date('Y-m-d') . '.log';

            self::ensureDirectory($baseDir);

            $data = [
                'time' => Date::current(),
                'ip' => Client::getAddress(),
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'uri' => self::maskUri((string)($_SERVER['REQUEST_URI'] ?? '')),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'referer' => $_SERVER['HTTP_REFERER'] ?? '',
                // 敏感字段脱敏：避免明文密钥/密码/令牌/会话 Cookie 落盘（历史泄露根因）
                'get' => maskSensitive($request->get()),
                'post' => maskSensitive($request->post()),
                'json' => maskSensitive($request->json()),
                'raw_body' => '', // 原始请求体含明文密钥（如 key=xxx&private_key=xxx），不再记录
                'cookies' => array_map(static fn($v) => '***', (array)$request->cookie()),
                'headers' => maskSensitive($request->header())
            ];

            $json = json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if ($json === false) {
                $json = json_encode([
                    'time' => date('Y-m-d H:i:s'),
                    'error' => 'json_encode failed',
                    'json_last_error_msg' => json_last_error_msg(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            file_put_contents(
                $logFile,
                \App\Util\RequestLogCrypto::encrypt((string)$json) . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * 给 URI 的查询串脱敏。
     *
     * get/post/headers 早就过 maskSensitive 了，唯独 uri 是原样落盘的——而表单一旦以 GET 提交，
     * 每个字段都会跑到查询串里。实测就这么把后台安全入口的密钥写进了日志（明文）。
     * 判据复用 maskSensitive 的那套字段名，两边保持一致。
     */
    private static function maskUri(string $uri): string
    {
        $pos = strpos($uri, '?');
        if ($pos === false) {
            return $uri;
        }

        $path = substr($uri, 0, $pos);
        $query = substr($uri, $pos + 1);
        if ($query === '') {
            return $uri;
        }

        $parts = [];
        foreach (explode('&', $query) as $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false) {
                $parts[] = $pair;
                continue;
            }
            $name = substr($pair, 0, $eq);
            $value = substr($pair, $eq + 1);
            $masked = maskSensitive([$name => $value]);
            $parts[] = $name . '=' . (string)($masked[$name] ?? $value);
        }

        return $path . '?' . implode('&', $parts);
    }

    /**
     * @param string $dir
     * @return void
     */
    private static function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('创建日志目录失败');
        }
    }
}