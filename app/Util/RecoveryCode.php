<?php
declare(strict_types=1);

namespace App\Util;

/**
 * 两步验证备用恢复码：明文只在生成时给用户看一次，库里只存 bcrypt 哈希，一次性使用。
 * 丢失验证器时可用一条恢复码登录/解绑。
 */
class RecoveryCode
{
    //去掉易混淆字符 0/O/1/I/L
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * 生成 n 条明文恢复码（每条两组 5 位，形如 A2C4E-8HK9P）。
     * @return string[]
     */
    public static function generate(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = self::randomGroup(5) . '-' . self::randomGroup(5);
        }
        return $codes;
    }

    /**
     * 把明文恢复码整批哈希成可入库的 JSON。
     * @param string[] $codes
     */
    public static function hashAll(array $codes): string
    {
        $hashes = array_map(static fn(string $c): string => password_hash(self::normalize($c), PASSWORD_BCRYPT), $codes);
        return json_encode(array_values($hashes), JSON_UNESCAPED_SLASHES);
    }

    /**
     * 校验并消费一条恢复码：命中则返回剔除该码后的新 JSON（可能是 []），未命中返回 null。
     */
    public static function consume(?string $storedJson, string $input): ?string
    {
        $normalized = self::normalize($input);
        if ($normalized === '') {
            return null;
        }
        $hashes = json_decode((string)$storedJson, true);
        if (!is_array($hashes)) {
            return null;
        }
        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && $hash !== '' && password_verify($normalized, $hash)) {
                unset($hashes[$index]);
                return json_encode(array_values($hashes), JSON_UNESCAPED_SLASHES);
            }
        }
        return null;
    }

    /**
     * 剩余可用恢复码数量。
     */
    public static function remaining(?string $storedJson): int
    {
        $hashes = json_decode((string)$storedJson, true);
        return is_array($hashes) ? count($hashes) : 0;
    }

    private static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    private static function randomGroup(int $len): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }
}
