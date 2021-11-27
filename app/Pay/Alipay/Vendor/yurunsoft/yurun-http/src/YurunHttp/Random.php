<?php

namespace Yurun\Util\YurunHttp;

abstract class Random
{
    /**
     * 随机整数.
     *
     * @param int $min
     * @param int $max
     *
     * @return int
     */
    public static function int($min = \PHP_INT_MIN, $max = \PHP_INT_MAX)
    {
        return mt_rand($min, $max);
    }

    /**
     * 随机生成小数.
     *
     * @param float $min
     * @param float $max
     * @param int   $precision 最大小数位数
     *
     * @return float
     */
    public static function number($min = \PHP_INT_MIN, $max = \PHP_INT_MAX, $precision = 2)
    {
        return round($min + mt_rand() / mt_getrandmax() * ($max - $min), $precision);
    }

    /**
     * 随机生成文本.
     *
     * @param string $chars
     * @param int    $min
     * @param int    $max
     *
     * @return string
     */
    public static function text($chars, $min, $max)
    {
        $length = mt_rand($min, $max);
        $charLength = mb_strlen($chars);
        $result = '';
        for ($i = 0; $i < $length; ++$i)
        {
            $result .= mb_substr($chars, mt_rand(1, $charLength) - 1, 1);
        }

        return $result;
    }

    /**
     * 随机生成字母.
     *
     * @param int $min
     * @param int $max
     *
     * @return string
     */
    public static function letter($min, $max)
    {
        return static::text('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', $min, $max);
    }

    /**
     * 随机生成数字.
     *
     * @param int $min
     * @param int $max
     *
     * @return string
     */
    public static function digital($min, $max)
    {
        return static::text('0123456789', $min, $max);
    }

    /**
     * 随机生成字母和数字.
     *
     * @param int $min
     * @param int $max
     *
     * @return string
     */
    public static function letterAndNumber($min, $max)
    {
        return static::text('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', $min, $max);
    }
}
