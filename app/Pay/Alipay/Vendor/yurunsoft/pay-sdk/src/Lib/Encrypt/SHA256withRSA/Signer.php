<?php

namespace Yurun\PaySDK\Lib\Encrypt\SHA256withRSA;

/**
 * @see https://github.com/wechatpay-apiv3/wechatpay-guzzle-middleware
 */
class Signer
{
    /**
     * Sign Message.
     *
     * @param string          $message    Message to sign
     * @param string          $serialNo   Merchant Certificate Serial Number
     * @param string|resource $privateKey Merchant Certificate Private Key (string - PEM formatted key, or resource - key returned by openssl_get_privatekey)
     *
     * @return SignatureResult
     */
    public static function sign($message, $serialNumber, $privateKey)
    {
        if (!\in_array('sha256WithRSAEncryption', openssl_get_md_methods(true)))
        {
            throw new \RuntimeException('当前 PHP 环境不支持 SHA256withRSA');
        }

        if (!openssl_sign($message, $sign, $privateKey, 'sha256WithRSAEncryption'))
        {
            throw new \UnexpectedValueException('签名验证过程发生了错误');
        }

        return new SignatureResult(base64_encode($sign), $serialNumber);
    }

    /**
     * Verify signature of message.
     *
     * @param string $message   message to verify
     * @param string $signautre signature of message
     * @param string $publicKey
     *
     * @return bool
     */
    public static function verify($message, $signature, $publicKey)
    {
        if (!\in_array('sha256WithRSAEncryption', openssl_get_md_methods(true)))
        {
            throw new \RuntimeException('当前PHP环境不支持SHA256withRSA');
        }
        $signature = base64_decode($signature);

        return 1 === openssl_verify($message, $signature, $publicKey, 'sha256WithRSAEncryption');
    }
}
