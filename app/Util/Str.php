<?php
declare(strict_types=1);

namespace App\Util;

/**
 * Class Str
 * @package App\Util
 */
class Str
{

    /**
     * 生成密码
     * @param string $pass
     * @param string $salt
     * @return string
     */
    public static function generatePassword(string $pass, string $salt): string
    {
        return sha1(md5(md5($pass) . md5($salt)));
    }

    /**
     * 生成随机字符串
     * @param int $length
     * @return string
     */
    public static function generateRandStr(int $length = 32): string
    {
        mt_srand();
        $md5 = md5(uniqid(md5((string)time())) . mt_rand(10000, 9999999));
        return substr($md5, 0, $length);
    }


    /**
     * 获取数据签名
     * @param array $data
     * @param string $appKey
     * @return string
     */
    public static function generateSignature(array $data, $appKey): string
    {
        unset($data['sign']);
        ksort($data);
        foreach ($data as $key => $val) {
            if ($val === '') {
                unset($data[$key]);
            }
        }
        return md5(urldecode(http_build_query($data) . "&key=" . (string)$appKey));
    }

    /**
     * 生成订单号
     * @return string
     */
    public static function generateTradeNo()
    {
        return mt_rand(100, 999) . date("ymdHis", time()) . mt_rand(100, 999);
    }

    /**
     * 随机生成浮动金额
     * @param float $amount
     * @param int $min
     * @param int $max
     * @return float
     */
    public static function generateRandAmount(float $amount, int $min, int $max): float
    {
        mt_srand();
        return $amount + (mt_rand($min, $max) / 100);
    }


    /**
     * @param int $type
     * @return string|int
     */
    public static function generateContact(int $type): string|int
    {
        return match ($type) {
            0 => self::generateRandStr(16),
            1 => "188" . mt_rand(1000, 9999) . mt_rand(1000, 9999),
            2 => self::generateRandStr(10) . "@system.do",
            3 => mt_rand(1000000, 99999999)
        };
    }
}