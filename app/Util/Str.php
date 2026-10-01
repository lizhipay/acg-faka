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
     * 生成新格式密码哈希（bcrypt，自带盐、慢哈希）。
     *
     * 旧格式 sha1(md5(md5·md5)) 是快哈希，脱库后可离线高速爆破；bcrypt 每次验证有成本，
     * 大幅抬高爆破代价。哈希长度 60 字符，落在 password/manage.password 的 varchar(64) 内。
     * salt 列对 bcrypt 已无意义（盐嵌在哈希里），但列 NOT NULL，调用方仍照旧写入一个盐值。
     */
    public static function hashPassword(string $pass): string
    {
        return password_hash($pass, PASSWORD_BCRYPT);
    }

    /**
     * 该哈希是否为旧格式、需要在下次登录时透明升级为 bcrypt。
     */
    public static function passwordNeedsUpgrade(string $storedHash): bool
    {
        //bcrypt/argon 前缀形如 $2y$ / $argon2；旧格式是 40 位十六进制 sha1
        return !str_starts_with($storedHash, '$');
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

        //新格式（bcrypt）：盐嵌在哈希里，salt 列不参与；旧管线兼容对 bcrypt 不适用
        if (str_starts_with($storedHash, '$')) {
            return password_verify($cleanInput, $storedHash)
                || ($rawInput !== null && $rawInput !== '' && $rawInput !== $cleanInput && password_verify($rawInput, $storedHash));
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
        //改用 CSPRNG：旧实现是 md5(uniqid+mt_rand)，种子是秒级时间、mt_rand 又非密码学随机，
        //整体可被离线推算——而本函数被 app_key(挂机支付/分销验签密钥)、salt、优惠券/商品编码、
        //各类会话/一次性令牌广泛使用，可预测即等于密钥/令牌可被爆破或伪造。
        //保持与旧实现一致的 [0-9a-f] 十六进制字符集与长度语义（调用方多处 strtoupper、且有定宽列依赖），
        //只把熵源换成 random_bytes。
        if ($length < 1) {
            return '';
        }
        return substr(bin2hex(random_bytes((int)ceil($length / 2))), 0, $length);
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
        //全随机 18 位（CSPRNG）。旧实现是 mt_rand(3)+明文时间戳(12)+mt_rand(3)：熵只有 ~19.3bit、
        //且下单时间明文可读，配合匿名 order/state 可按时间窗枚举订单号→拉卡密。改为 random_int 生成，
        //去掉时间戳、不再用非密码学的 mt_rand。仍是 18 位纯数字（兼容 ^\d{18}$ 匹配与支付网关回传），
        //首位取 1-9 避免前导零；trade_no 有唯一索引，极小概率撞号时入库失败、上层照旧重试/报错。
        $tradeNo = (string)random_int(1, 9);
        for ($i = 0; $i < 17; $i++) {
            $tradeNo .= (string)random_int(0, 9);
        }
        return $tradeNo;
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