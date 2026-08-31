<?php
declare (strict_types=1);

namespace Kernel\Util;

use App\Model\Config;
use App\Util\Client;
use Kernel\Context\Interface\Request;

class RequestLogger
{

    /**
     * 记录当前请求
     */
    public static function logCurrentRequest(Request $request): void
    {
        try {
            if (!file_exists(BASE_PATH . '/kernel/Install/Lock')) {
                return;
            }

            if (Config::get("request_log") == 1) {
                return;
            }
            $config = config("database");

            $baseDir = rtrim(BASE_PATH, DIRECTORY_SEPARATOR) . '/runtime/request/' . md5($config['password']);
            $logFile = $baseDir . '/' . date('Y-m-d') . '.log';

            self::ensureDirectory($baseDir);

            $data = [
                'time' => Date::current(),
                'ip' => Client::getAddress(),
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'uri' => $_SERVER['REQUEST_URI'],
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

            file_put_contents($logFile, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            return;
        }
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