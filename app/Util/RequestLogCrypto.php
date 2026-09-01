<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Config;

final class RequestLogCrypto
{
    public const PREFIX = 'ACGL1:';

    /**
     * 密钥只存数据库，且走 Config 的直读直写通道——不进 runtime/config、也不进模板的 $config。
     * 网站目录被整个打包带走时，日志跟着走，密钥不跟着走。
     */
    private const KEY_CONFIG = 'request_log_key';

    private static ?string $keyCache = null;

    private static bool $resolved = false;

    /**
     * @return string
     * @throws \RuntimeException 库里没有密钥时抛出，调用方必须放弃写入而不是退回明文
     */
    public static function key(): string
    {
        $key = self::resolve();
        if ($key === null) {
            throw new \RuntimeException('请求日志密钥不存在');
        }
        return $key;
    }

    /**
     * @return bool
     */
    public static function available(): bool
    {
        return self::resolve() !== null;
    }

    /**
     * 只在安装、升级、后台显式调用。绝不在写日志的路径上自动生成：
     * 并发请求会各自生成一把、互相覆盖，先写下的那几行日志就永久解不开了。
     *
     * @return string
     * @throws \RuntimeException
     */
    public static function generate(): string
    {
        $key = random_bytes(32);
        if (!self::store($key)) {
            throw new \RuntimeException('无法保存请求日志密钥');
        }
        self::$resolved = true;
        return self::$keyCache = $key;
    }

    public static function keyB64(): string
    {
        $key = self::resolve();
        return $key === null ? '' : base64_encode($key);
    }

    public static function setKeyB64(string $b64): bool
    {
        $raw = self::decodeKey($b64);
        if ($raw === null || !self::store($raw)) {
            return false;
        }
        self::$resolved = true;
        self::$keyCache = $raw;
        return true;
    }

    /**
     * @param string $plain
     * @return string
     * @throws \RuntimeException
     */
    public static function encrypt(string $plain): string
    {
        $key = self::key();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('请求日志加密失败');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $line, ?string $key = null): ?string
    {
        $line = trim($line);
        if ($line === '' || !str_starts_with($line, self::PREFIX)) {
            return null;
        }
        $blob = base64_decode(substr($line, strlen(self::PREFIX)), true);
        if ($blob === false || strlen($blob) < 29) {
            return null;
        }
        $plain = openssl_decrypt(
            substr($blob, 28),
            'aes-256-gcm',
            $key ?? self::key(),
            OPENSSL_RAW_DATA,
            substr($blob, 0, 12),
            substr($blob, 12, 16)
        );
        return $plain === false ? null : $plain;
    }

    public static function resetCache(): void
    {
        self::$keyCache = null;
        self::$resolved = false;
    }

    /**
     * @return string|null
     */
    private static function resolve(): ?string
    {
        if (self::$resolved) {
            return self::$keyCache;
        }
        self::$resolved = true;

        try {
            self::$keyCache = self::decodeKey(Config::secret(self::KEY_CONFIG));
        } catch (\Throwable) {
            self::$keyCache = null;
        }

        return self::$keyCache;
    }

    private static function decodeKey(string $b64): ?string
    {
        $raw = base64_decode(trim($b64), true);
        return $raw !== false && strlen($raw) === 32 ? $raw : null;
    }

    private static function store(string $key): bool
    {
        try {
            return Config::putSecret(self::KEY_CONFIG, base64_encode($key));
        } catch (\Throwable) {
            return false;
        }
    }
}
