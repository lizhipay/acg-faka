<?php
declare(strict_types=1);

namespace App\Util;

/**
 * 基于时间的一次性口令（TOTP, RFC 6238），兼容 Google Authenticator / 微软 Authenticator。
 * 算法固定为 SHA1 / 6 位 / 30 秒步长。原生实现，无需第三方库。
 */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; //RFC 4648 Base32

    /**
     * 生成随机 Base32 密钥（默认 16 字符 = 80 位熵）。
     */
    public static function generateSecret(int $length = 16): string
    {
        $bytes = random_bytes($length);
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::ALPHABET[ord($bytes[$i]) & 31];
        }
        return $secret;
    }

    /**
     * 校验 6 位动态码，默认容忍前后各 1 个 30 秒窗口（应对客户端时间偏差）。
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if ($secret === '' || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $step = (int)floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::calculate($secret, $step + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * otpauth:// 链接，用于生成二维码或手动录入。
     */
    public static function keyUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ]);
        return "otpauth://totp/{$label}?{$query}";
    }

    /**
     * 计算指定时间步的 6 位口令。
     */
    private static function calculate(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '';
        }
        //8 字节大端时间计数器（高 4 字节为 0，2106 年前足够）
        $binary = pack('N*', 0) . pack('N*', $step);
        $hash = hash_hmac('sha1', $binary, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $truncated =
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string)($truncated % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Base32 解码。
     */
    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(rtrim($b32, '='));
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';
        $len = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $val = strpos(self::ALPHABET, $b32[$i]);
            if ($val === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        return $output;
    }
}
