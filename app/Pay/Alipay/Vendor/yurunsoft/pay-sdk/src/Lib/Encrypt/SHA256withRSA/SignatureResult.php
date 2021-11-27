<?php

namespace Yurun\PaySDK\Lib\Encrypt\SHA256withRSA;

/**
 * @see https://github.com/wechatpay-apiv3/wechatpay-guzzle-middleware
 */
class SignatureResult
{
    /**
     * Signature.
     *
     * @var string
     */
    public $sign;

    /**
     * Certificate Serial Number.
     *
     * @var string
     */
    public $certificateSerialNumber;

    /**
     * Constructor.
     */
    public function __construct($sign, $serialNumber)
    {
        $this->sign = $sign;
        $this->certificateSerialNumber = $serialNumber;
    }

    /**
     * Get Signature.
     *
     * @return string
     */
    public function getSign()
    {
        return $this->sign;
    }

    /**
     * Get Certificate Serial Number.
     *
     * @return string
     */
    public function getCertificateSerialNumber()
    {
        return $this->certificateSerialNumber;
    }
}
