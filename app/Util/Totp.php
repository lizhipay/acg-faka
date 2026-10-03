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
     *
     * 注意：这是**无状态**校验，同一个码在其有效窗口（约 90 秒）内会一直通过。
     * 绑定两步验证（候选密钥尚未归属任何账号）用它即可；凡是校验账号**已绑定**密钥、
     * 且动作敏感（登录、资金验证、关闭两步验证等）的地方，应改用 verifyAndConsume()
     * 做一次性消费，防止被嗅探到的码在窗口内重放。
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        return self::matchStep($secret, $code, $window) !== null;
    }

    /**
     * 校验并**一次性消费**动态码：按会员记录"上次用过的时间步"，只接受比它更新的步，
     * 使同一个 6 位码（及更早的码）在窗口内无法第二次使用。
     *
     * 存储沿用 Throttle 的 flock 文件锁（runtime/totp），无需数据库改动；缓存不可用时
     * 退回"只做无状态校验"放行，避免误伤正常用户（与 Throttle 的失败即放行一致）。
     *
     * @param string $identity 账号命名空间，如 "user:123" / "manage:1"（会员与管理员 id 各自独立，防撞键）
     */
    public static function verifyAndConsume(string $secret, string $code, string $identity, int $window = 1): bool
    {
        $step = self::matchStep($secret, $code, $window);
        if ($step === null) {
            return false;
        }
        if (trim($identity) === '') {
            return true;
        }

        $file = BASE_PATH . '/runtime/totp/' . md5('totp_used:' . $identity);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return true; //缓存打不开：不拦正常用户（降级为无状态校验）
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                fclose($fp);
                return true;
            }
            $last = (int)trim((string)stream_get_contents($fp));
            if ($step <= $last) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return false; //该码（或更早的码）已被用过
            }
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, (string)$step);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            return true;
        } catch (\Throwable $e) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
            return true;
        }
    }

    /**
     * 返回与动态码匹配的时间步（容忍 ±$window 个步），无匹配返回 null。
     */
    private static function matchStep(string $secret, string $code, int $window = 1): ?int
    {
        $code = trim($code);
        if ($secret === '' || !preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $step = (int)floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::calculate($secret, $step + $i), $code)) {
                return $step + $i;
            }
        }
        return null;
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
