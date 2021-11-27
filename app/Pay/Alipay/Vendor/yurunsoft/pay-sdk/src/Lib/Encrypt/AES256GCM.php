<?php

namespace Yurun\PaySDK\Lib\Encrypt;

/**
 * @see https://github.com/wechatpay-apiv3/wechatpay-guzzle-middleware/blob/master/src/Util/AesUtil.php
 */
class AES256GCM
{
    const KEY_LENGTH_BYTE = 32;
    const AUTH_TAG_LENGTH_BYTE = 16;

    /**
     * Decrypt AEAD_AES_256_GCM ciphertext.
     *
     * @param string $aesKey         API V3 Key
     * @param string $associatedData AES GCM additional authentication data
     * @param string $nonceStr       AES GCM nonce
     * @param string $ciphertext     AES GCM cipher text
     *
     * @return string|bool Decrypted string on success or FALSE on failure
     */
    public static function decryptToString($aesKey, $associatedData, $nonceStr, $ciphertext)
    {
        if (self::KEY_LENGTH_BYTE != \strlen($aesKey))
        {
            throw new \InvalidArgumentException('无效的ApiV3Key，长度应为32个字节');
        }

        $ciphertext = base64_decode($ciphertext);
        if (\strlen($ciphertext) <= self::AUTH_TAG_LENGTH_BYTE)
        {
            return false;
        }

        // ext-sodium (default installed on >= PHP 7.2)
        if (\function_exists('\sodium_crypto_aead_aes256gcm_is_available') &&
            sodium_crypto_aead_aes256gcm_is_available())
        {
            return sodium_crypto_aead_aes256gcm_decrypt($ciphertext, $associatedData, $nonceStr, $aesKey);
        }

        // ext-libsodium (need install libsodium-php 1.x via pecl)
        if (\function_exists('\Sodium\crypto_aead_aes256gcm_is_available') &&
            \Sodium\crypto_aead_aes256gcm_is_available())
        {
            return \Sodium\crypto_aead_aes256gcm_decrypt($ciphertext, $associatedData, $nonceStr, $aesKey);
        }

        // openssl (PHP >= 7.1 support AEAD)
        if (\PHP_VERSION_ID >= 70100 && \in_array('aes-256-gcm', openssl_get_cipher_methods()))
        {
            $ctext = substr($ciphertext, 0, -self::AUTH_TAG_LENGTH_BYTE);
            $authTag = substr($ciphertext, -self::AUTH_TAG_LENGTH_BYTE);

            return openssl_decrypt($ctext, 'aes-256-gcm', $aesKey, \OPENSSL_RAW_DATA, $nonceStr,
                $authTag, $associatedData);
        }

        throw new \RuntimeException('AEAD_AES_256_GCM需要PHP 7.1以上或者安装libsodium-php');
    }
}
