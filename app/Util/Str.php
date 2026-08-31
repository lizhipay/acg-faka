<?php
declare(strict_types=1);

namespace App\Util;

use Kernel\Waf\Filter;
use Kernel\Waf\Firewall;

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
     * 校验账号密码，兼容旧清洗管线时代的哈希（#833）
     *
     * 旧版 Firewall 会对已解码的输入再 urldecode 一次，并把裸 & 实体化成 &amp;，
     * 含特殊字符的密码在当年注册/改密时哈希的是转义后的形态。管线修正后，
     * 直接比对会失败，这里用旧管线重算一个候选值兜底，让老账号无缝登录。
     *
     * @param string $storedHash 库中的密码哈希
     * @param string $salt 账号盐
     * @param string $cleanInput 当前管线清洗后的输入（如 $_POST['password']）
     * @param string|null $rawInput 未清洗的原始输入（Request::unsafePost），用于重放旧管线
     * @return bool
     */
    public static function verifyPassword(string $storedHash, string $salt, string $cleanInput, ?string $rawInput = null): bool
    {
        if ($storedHash === '') {
            return false;
        }

        if (hash_equals($storedHash, self::generatePassword($cleanInput, $salt))) {
            return true;
        }

        if ($rawInput === null || $rawInput === '') {
            return false;
        }

        //旧管线 = 旧版 xssKiller + 超全局的 STRING_UNSIGNED 过滤，两步都要重放
        $firewall = Firewall::inst();
        $legacy = $firewall->filterContent($firewall->xssKillerLegacy($rawInput), Filter::STRING_UNSIGNED);

        return is_string($legacy)
            && $legacy !== $cleanInput
            && hash_equals($storedHash, self::generatePassword($legacy, $salt));
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
     * @param mixed $sign
     * @return bool
     */
    public static function isInvalidSign(mixed $sign): bool
    {
        if (!is_string($sign)) {
            return true;
        }

        $sign = trim($sign);

        return $sign === '';
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

    /**
     * @param string $str
     * @return bool
     */
    public static function isValid(string $str): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9]+$/', $str);
    }


    /**
     * @param mixed $str
     * @param string $local
     * @return bool
     */
    public static function safetyEquals(mixed $str, string $local): bool
    {
        if (!is_string($str) || $str === '') {
            return false;
        }

        return hash_equals($local, $str);
    }
}