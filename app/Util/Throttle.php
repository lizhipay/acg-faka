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
        $cache = self::cache();
        $now = time();
        $count = 0;
        $reset = $now + $window;

        try {
            if ($cache->has($key)) {
                $rec = $cache->get($key);
                // OPTIONS_JSON 解出的是 stdClass
                if (is_object($rec) && isset($rec->r) && (int)$rec->r > $now) {
                    $count = (int)($rec->c ?? 0);
                    $reset = (int)$rec->r;
                }
            }
        } catch (\Throwable $e) {
            // 缓存异常不应影响主流程；按未超限放行，避免误伤正常用户
            return false;
        }

        $count++;
        try {
            $cache->set($key, ['c' => $count, 'r' => $reset]);
        } catch (\Throwable $e) {
        }

        return $count > $limit;
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
