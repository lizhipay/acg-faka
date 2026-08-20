<?php
declare(strict_types=1);

namespace App\Util;


/**
 * 支付接口拨测的临时记录。
 *
 * 拨测刻意不写 order 表——测试单混进真实订单会污染统计和商品订单列表。但要在界面上看到
 * "付了没有"，总得有个地方记一笔，于是落在 runtime 下的小文件里：短时效、自动清理、
 * 不建表不改库，坏了删掉整个目录就行，碰不到任何生意数据。
 */
class PayTest
{
    /**
     * 记录活多久。拨测是当场看结果的事，过期就没意义了
     */
    private const TTL = 3600;

    /**
     * 目录里最多留多少条，防止有人刷接口把磁盘写满
     */
    private const MAX_FILES = 200;

    /**
     * 订单号同时是文件名，必须先卡死格式再拼路径
     *
     * @param string $tradeNo
     * @return bool
     */
    public static function isValidTradeNo(string $tradeNo): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{6,32}$/D', $tradeNo) === 1;
    }

    /**
     * @return string
     */
    private static function directory(): string
    {
        return BASE_PATH . '/runtime/paytest';
    }

    /**
     * @param string $tradeNo
     * @return string|null
     */
    private static function path(string $tradeNo): ?string
    {
        if (!self::isValidTradeNo($tradeNo)) {
            return null;
        }
        return self::directory() . '/' . $tradeNo . '.json';
    }

    /**
     * 写入/覆盖一条记录。
     *
     * @param string $tradeNo
     * @param array $data
     * @return void
     */
    public static function put(string $tradeNo, array $data): void
    {
        $path = self::path($tradeNo);
        if ($path === null) {
            return;
        }

        $directory = self::directory();
        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $data['trade_no'] = $tradeNo;
        $data['update_time'] = Date::current();

        @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        self::prune();
    }

    /**
     * 在已有记录上改几个字段。记录不存在就什么都不做——
     * 回调必须落在一次真实的拨测上，不能凭空造一条出来。
     *
     * @param string $tradeNo
     * @param array $patch
     * @return bool 记录存在并已更新
     */
    public static function patch(string $tradeNo, array $patch): bool
    {
        $current = self::get($tradeNo);
        if ($current === null) {
            return false;
        }
        self::put($tradeNo, array_replace($current, $patch));
        return true;
    }

    /**
     * @param string $tradeNo
     * @return array|null 不存在或已过期返回 null
     */
    public static function get(string $tradeNo): ?array
    {
        $path = self::path($tradeNo);
        if ($path === null || !is_file($path)) {
            return null;
        }

        if (time() - (int)@filemtime($path) > self::TTL) {
            @unlink($path);
            return null;
        }

        $raw = @file_get_contents($path);
        $data = $raw === false ? null : json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * 清掉过期的；数量还超标就把最旧的删到线下。
     *
     * @return void
     */
    private static function prune(): void
    {
        $files = @glob(self::directory() . '/*.json') ?: [];
        $now = time();
        $alive = [];

        foreach ($files as $file) {
            $time = (int)@filemtime($file);
            if ($now - $time > self::TTL) {
                @unlink($file);
                continue;
            }
            $alive[$file] = $time;
        }

        if (count($alive) <= self::MAX_FILES) {
            return;
        }

        asort($alive);
        foreach (array_slice(array_keys($alive), 0, count($alive) - self::MAX_FILES) as $file) {
            @unlink($file);
        }
    }
}
