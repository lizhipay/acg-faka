<?php

namespace Yurun\PaySDK\Lib\Encrypt;

class RSA2 extends Base
{
    public static function signPrivate($data, $key)
    {
        if (!\defined('OPENSSL_ALGO_SHA256'))
        {
            throw new \Exception('SHA256需要在PHP>=5.4.8下才可使用');
        }
        $key = static::parseKey($key);
        $key = "-----BEGIN RSA PRIVATE KEY-----\n{$key}\n-----END RSA PRIVATE KEY-----";
        openssl_sign($data, $sign, $key, \OPENSSL_ALGO_SHA256);

        return $sign;
    }

    public static function signPrivateFromFile($data, $fileName)
    {
        if (!\defined('OPENSSL_ALGO_SHA256'))
        {
            throw new \Exception('SHA256需要在PHP>=5.4.8下才可使用');
        }
        $key = file_get_contents($fileName);
        $res = openssl_get_privatekey($key);
        if (!$res)
        {
            throw new \Exception('私钥文件格式错误');
        }
        openssl_sign($data, $sign, $res, \OPENSSL_ALGO_SHA256);
        if (PHP_VERSION_ID < 80000)
        {
            openssl_free_key($res);
        }

        return $sign;
    }

    public static function verifyPublic($data, $key, $sign)
    {
        if (!\defined('OPENSSL_ALGO_SHA256'))
        {
            throw new \Exception('SHA256需要在PHP>=5.4.8下才可使用');
        }
        $key = static::parseKey($key);
        $key = "-----BEGIN PUBLIC KEY-----\n{$key}\n-----END PUBLIC KEY-----";

        return 1 === openssl_verify($data, $sign, $key, \OPENSSL_ALGO_SHA256);
    }

    public static function verifyPublicFromFile($data, $fileName, $sign)
    {
        if (!\defined('OPENSSL_ALGO_SHA256'))
        {
            throw new \Exception('SHA256需要在PHP>=5.4.8下才可使用');
        }
        $key = file_get_contents($fileName);
        $res = openssl_get_publickey($key);
        if (!$res)
        {
            throw new \Exception('公钥文件格式错误');
        }
        $result = openssl_verify($data, $sign, $res, \OPENSSL_ALGO_SHA256);
        if (PHP_VERSION_ID < 80000)
        {
            openssl_free_key($res);
        }

        return 1 === $result;
    }
}
