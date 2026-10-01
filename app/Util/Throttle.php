<?php
declare(strict_types=1);

namespace App\Util;

use Kernel\Cache\Cache;

/**
 * 轻量限流器（服务端计数，按 key 独立窗口）。
 * 基于文件缓存实现，无需 Redis；用于挡住未登录接口的暴力枚举/爆破
 * （如卡密查询密码爆破、后台登录爆破）。计数存于项目内 runtime/throttle，
 * 不含任何敏感信息，且受 nginx /runtime 拦截保护。
 */
class Throttle
{
    private static ?Cache $cache = null;

    private static function cache(): Cache
    {
        if (self::$cache === null) {
            self::$cache = new Cache(BASE_PATH . '/runtime/throttle', Cache::OPTIONS_JSON);
        }
        return self::$cache;
    }

    /**
     * 记一次访问并判断是否已超过窗口内允许的次数。
     * @param string $key 唯一标识，如 "secret:{tradeNo}:{ip}"
     * @param int $limit 窗口内允许的最大次数
     * @param int $window 窗口秒数
     * @return bool true=已超限（调用方应拦截）
     */
    public static function tooMany(string $key, int $limit, int $window): bool
    {
        $now = time();
        $file = BASE_PATH . '/runtime/throttle/' . md5($key);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // 读-改-写必须在同一把排他锁内完成，否则并发请求都读到旧计数后各写回 +1，
        // 单窗口内远超 limit 次仍被放行（TOCTOU 失更新）。这里直接用 fopen('c+')+flock(LOCK_EX)：
        // 'c' 模式会创建但不截断文件，避开 Kernel\File\File 构造时 fopen('w') 在并发首次创建下
        // 把计数清零的隐患（旧实现经 Cache::set 走 writeForLock 也命中该隐患）。
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return false; //打不开缓存不拦正常用户
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                fclose($fp);
                return false;
            }
            $contents = (string)stream_get_contents($fp);
            $c = 0;
            $reset = $now + $window;
            $rec = $contents !== '' ? json_decode(base64_decode($contents), true) : null;
            if (is_array($rec) && isset($rec['r']) && (int)$rec['r'] > $now) {
                $c = (int)($rec['c'] ?? 0);
                $reset = (int)$rec['r'];
            }
            $c++;
            $out = base64_encode((string)json_encode(['c' => $c, 'r' => $reset]));
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, $out);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            return $c > $limit;
        } catch (\Throwable $e) {
            // 缓存异常不应影响主流程；按未超限放行，避免误伤正常用户
            @flock($fp, LOCK_UN);
            @fclose($fp);
            return false;
        }
    }

    /**
     * 清除某个 key 的计数（如登录/验证成功后重置）。
     * @param string $key
     */
    public static function clear(string $key): void
    {
        try {
            self::cache()->del($key);
        } catch (\Throwable $e) {
        }
    }
}
