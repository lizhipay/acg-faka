<?php
declare(strict_types=1);

namespace Kernel\Util;


use function Amp\call;

class Date
{

    const TYPE_START = 0x1;
    const TYPE_END = 0x2;

    /**
     * 获取本周某天时间，1代表星期一，7代表星期日
     * @param int $weekday 目标星期几
     * @return string 目标日期
     */
    public static function getDateByWeekday(int $weekday): string
    {
        $currentDate = new \DateTime(); // 当前日期
        $currentWeekday = (int)$currentDate->format('N'); // 当前是星期几，1（星期一）到 7（星期日）
        $daysToMonday = $currentWeekday - 1; // 如果今天是星期一，差异为0
        $daysFromMondayToTarget = $weekday - 1;
        $totalDifference = $daysFromMondayToTarget - $daysToMonday;
        $targetDate = (clone $currentDate)->modify("{$totalDifference} days");
        return $targetDate->format('Y-m-d');
    }

    /**
     * 获取本月第一天日期
     * @return string
     */
    public static function getFirstDayOfMonth(): string
    {
        $currentDate = new \DateTime();
        $firstDayOfMonth = $currentDate->modify('first day of this month');
        return $firstDayOfMonth->format('Y-m-d');
    }

    /**
     * 获取本月最后一天日期
     * @return string
     */
    public static function getLastDayOfMonth(): string
    {
        $currentDate = new \DateTime();
        $lastDayOfMonth = $currentDate->modify('last day of this month');
        return $lastDayOfMonth->format('Y-m-d');
    }

    /**
     * 获取上个月的第一天
     * @return string
     */
    public static function getFirstDayOfLastMonth(): string
    {
        $currentDate = new \DateTime();
        $firstDayOfLastMonth = $currentDate->modify('first day of last month');
        return $firstDayOfLastMonth->format('Y-m-d');
    }

    /**
     * 获取上个月的最后一天
     * @return string
     */
    public static function getLastDayOfLastMonth(): string
    {
        $currentDate = new \DateTime();
        $lastDayOfLastMonth = $currentDate->modify('last day of last month');
        return $lastDayOfLastMonth->format('Y-m-d');
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


    /**
     * @return int
     */
    public static function timestamp(): int
    {
        return (int)(microtime(true) * 1000);
    }

    /**
     * @param callable $func
     * @param callable $end
     * @return void
     */
    public static function runTimer(callable $func, callable $end): void
    {
        $start = self::timestamp();
        call_user_func($func);
        call_user_func($end, self::timestamp() - $start);
    }
}