<?php
declare(strict_types=1);

namespace App\Util;


class Date
{

    const TYPE_START = 0x1;
    const TYPE_END = 0x2;

    /**
     * 获取本周某天时间
     * @param int $week
     * @param int $type
     * @return string
     */
    public static function weekDay(int $week = 1, int $type = self::TYPE_START): string
    {
        $w = date('w');
        $w = $w == 0 ? 7 : $w;

        $fix = $type == self::TYPE_START ? " 00:00:00" : " 23:59:59";

        if ($week > $w) {
            return date("Y-m-d H:i:s", strtotime(date("Y-m-d") . $fix) + (($week - $w) * 86400));
        } else if ($week < $w) {
            return date("Y-m-d H:i:s", strtotime(date("Y-m-d") . $fix) - (($w - $week) * 86400));
        } else {
            return date("Y-m-d H:i:s", strtotime(date("Y-m-d") . $fix));
        }
    }


    /**
     * 判断当前时间是否晚上
     * @return bool
     */
    public static function isNight(): bool
    {
        $h = date('H');
        if ($h >= 8 && $h <= 20) {
            return false;
        }
        return true;
    }

    /**
     * 时间计算器：按天偏移取那一天的起点或终点。
     *
     * 终点给的是当天 23:59:59，而不是次日 00:00:00——统计一律走 whereBetween，
     * 而它是闭区间，拿次日零点当终点会让零点整那条记录同时落进前后两个窗口
     * （既算"今日"又算"昨日"）。create_time 是秒精度的 datetime，所以
     * `<= 当天 23:59:59` 与 `< 次日 00:00:00` 完全等价，不会漏记。
     *
     * @param int $day 天偏移，0=今天、-1=昨天
     * @param int $type TYPE_START=当天 00:00:00，TYPE_END=当天 23:59:59
     * @return string
     */
    public static function calcDay(int $day = 0, int $type = self::TYPE_START): string
    {
        $fix = $type === self::TYPE_END ? ' 23:59:59' : ' 00:00:00';
        return date("Y-m-d", time() + ($day * 86400)) . $fix;
    }

    /**
     * 本月起止。
     *
     * 终点是本月最后一天 23:59:59。不能拿"今天 00:00:00"当终点——那样今天
     * 零点之后产生的订单全都不算进"本月数据"，要等到第二天查才看得见
     * （GitHub #879）。
     *
     * @param int $type TYPE_START=本月 1 日 00:00:00，TYPE_END=本月最后一天 23:59:59
     * @return string
     */
    public static function monthDay(int $type = self::TYPE_START): string
    {
        //Y-m-t 的 t 是当月天数，闰年、大小月都不用自己判断
        return $type === self::TYPE_END ? date("Y-m-t 23:59:59") : date("Y-m-01 00:00:00");
    }

    /**
     * 获取当前时间
     * @param string|null $format
     * @return string
     */
    public static function current(string $format = null): string
    {
        return $format ? date($format, time()) : date("Y-m-d H:i:s", time());
    }

    /**
     * 获取初始时间
     * @return string
     */
    public static function initialDate(): string
    {
        return "0000-00-00 00:00:00";
    }

    /**
     * 将时间转换为文字提示
     * @param string $date
     * @return string
     */
    public static function sauce(string $date): string
    {
        $datetime = strtotime($date);
        $now = time();
        $midTime = $now - $datetime;
        if ($midTime < 60) {
            return '刚刚';
        } elseif ($midTime < 1800) {
            return self::timeCalculate($midTime, 60, 30) . '分钟前';
        } elseif ($midTime < 3600) {
            return "半小时前";
        } elseif ($midTime < 86400) {
            return self::timeCalculate($midTime, 3600, 24) . '小时前';
        } elseif ($midTime < 2592000) {
            return self::timeCalculate($midTime, 86400, 30) . '天前';
        } elseif ($midTime < 31104000) {
            return self::timeCalculate($midTime, 2592000, 12) . '个月前';
        } elseif ($midTime > 31104000) {
            return self::timeCalculate($midTime, 31104000, 99) . '年前';
        }
        return "超出范围";
    }

    /**
     * 时间间隔计算
     * @param int $midTime
     * @param int $serious
     * @param int $ergodic
     * @param int $initial
     * @return int
     */
    private static function timeCalculate(int $midTime, int $serious, int $ergodic, int $initial = 2): int
    {
        for ($i = $initial; $i <= $ergodic; $i++) {
            if ($midTime < $i * $serious) {
                return ($i - 1);
            }
        }
        return 1;
    }
}