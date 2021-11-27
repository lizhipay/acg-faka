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
     * 时间计算器
     * @param int $day
     * @return string
     */
    public static function calcDay(int $day = 0): string
    {
        return date("Y-m-d", time() + ($day * 86400)) . ' 00:00:00';
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