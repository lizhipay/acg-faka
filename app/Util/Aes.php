<?php
declare(strict_types=1);

namespace App\Util;

/**
 * AES加密解密类
 */
class Aes
{
    /**
     * AES->CBC 加密
     * @param $data
     * @param string $key
     * @param string $iv
     * @return string
     */
    public static function encrypt(mixed $data, string $key, string $iv): string
    {
        return base64_encode(openssl_encrypt(serialize($data), 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv));
    }

    /**
     * AES->CBC 解密
     * @param string $data
     * @param string $key
     * @param string $iv
     * @return mixed
     */
    public static function decrypt(string $data, string $key, string $iv): mixed
    {
        return unserialize((string)openssl_decrypt(base64_decode($data), 'aes-128-cbc', $key, 1, $iv));
    }
}