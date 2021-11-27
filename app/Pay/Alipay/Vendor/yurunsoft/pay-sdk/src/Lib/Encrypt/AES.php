<?php

namespace Yurun\PaySDK\Lib\Encrypt;

class AES extends Base
{
    public static function encrypt($data, $key)
    {
        return openssl_encrypt($data, 'AES-128-CBC', $key, 0, str_repeat(\chr(0), openssl_cipher_iv_length('AES-128-CBC')));
    }

    public static function decrypt($data, $key)
    {
        return openssl_decrypt($data, 'AES-128-CBC', $key, 0, str_repeat(\chr(0), openssl_cipher_iv_length('AES-128-CBC')));
    }

    public static function encrypt256($data, $key)
    {
        return openssl_encrypt($data, 'AES-256-ECB', $key, 0, str_repeat(\chr(0), openssl_cipher_iv_length('AES-256-ECB')));
    }

    public static function decrypt256($data, $key, $options = 0)
    {
        return openssl_decrypt($data, 'AES-256-ECB', $key, $options, str_repeat(\chr(0), openssl_cipher_iv_length('AES-256-ECB')));
    }
}
