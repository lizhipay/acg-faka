<?php

namespace Yurun\PaySDK\Weixin\Params;

use Yurun\PaySDK\PublicBase;

/**
 * 微信支付-公共参数类.
 */
class PublicParams extends PublicBase
{
    /**
     * 微信分配的子商户公众账号ID，服务商、银行服务商需要。
     *
     * @var string
     */
    public $sub_appid;

    /**
     * 微信支付分配的商户号.
     *
     * @var string
     */
    public $mch_id;

    /**
     * 微信支付分配的子商户号，开发者模式下必填，服务商、银行服务商需要。
     *
     * @var string
     */
    public $sub_mch_id;

    /**
     * 签名类型，目前支持HMAC-SHA256和MD5，默认为MD5.
     *
     * @var string
     */
    public $sign_type = 'MD5';

    /**
     * API密钥
     * 在API调用时用来按照指定规则对你的请求参数进行签名，服务器收到你的请求时会进行签名验证，既可以界定你的身份也可以防止其他人恶意篡改请求数据。
     * 部分API单独使用API密钥签名进行安全加固，部分安全性要求更高的API会要求使用API密钥签名和API证书同时进行安全加固。
     *
     * @var string
     */
    public $key;

    /**
     * V3 版本接口的密钥.
     *
     * @var string
     */
    public $keyV3;

    /**
     * 证书地址
     *
     * @var string
     */
    public $certPath;

    /**
     * 证书序列号.
     *
     * @var string
     */
    public $certSerialNumber;

    /**
     * 私钥地址
     *
     * @var string
     */
    public $keyPath;

    /**
     * V3 接口的 API 证书地址
     *
     * @var string
     */
    public $apiCertPath;

    /**
     * 交易保障上报级别.
     *
     * @var int
     */
    public $reportLevel = self::REPORT_LEVEL_ERROR;

    /**
     * 不上报.
     */
    const REPORT_LEVEL_NONE = 0;

    /**
     * 上报所有请求
     */
    const REPORT_LEVEL_ALL = 1;

    /**
     * 只上报出错请求
     */
    const REPORT_LEVEL_ERROR = 2;

    public function __construct()
    {
        $this->apiDomain = 'https://api.mch.weixin.qq.com/';
    }
}
