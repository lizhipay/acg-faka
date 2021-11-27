<?php

namespace Yurun\PaySDK\AlipayApp\Params;

use Yurun\PaySDK\PublicBase;

/**
 * 支付宝开放平台接口公共参数类.
 */
class PublicParams extends PublicBase
{
    /**
     * 仅支持JSON.
     *
     * @var string
     */
    public $format = 'json';

    /**
     * 请求使用的编码格式，如utf-8,gbk,gb2312等.
     *
     * @var string
     */
    public $charset = 'utf-8';

    /**
     * 商户生成签名字符串所使用的签名算法类型，目前支持RSA2和RSA，推荐使用RSA2。
     * RSA2需要PHP版本>=5.4.8下才可使用。
     *
     * @var string
     */
    public $sign_type = 'RSA2';

    /**
     * 调用的接口版本，固定为：1.0.
     *
     * @var string
     */
    public $version = '1.0';

    /**
     * 私有证书文件内容.
     *
     * @var string
     */
    public $appPrivateKey;

    /**
     * 私有证书文件地址，不为空时优先使用文件地址
     *
     * @var string
     */
    public $appPrivateKeyFile;

    /**
     * 公有证书文件内容.
     *
     * @var string
     */
    public $appPublicKey;

    /**
     * 公有证书文件地址，不为空时优先使用文件地址
     *
     * @var string
     */
    public $appPublicKeyFile;

    /**
     * 是否使用AES加密解密数据.
     *
     * @var bool
     */
    public $isUseAES = false;

    /**
     * AES密钥.
     *
     * @var string
     */
    public $aesKey;

    /**
     * 是否使用公钥证书模式.
     *
     * @var bool
     */
    public $usePublicKeyCert = false;

    /**
     * 支付宝公钥证书文件路径.
     *
     * @var string
     */
    public $alipayCertPath;

    /**
     * 支付宝根证书文件路径.
     *
     * @var string
     */
    public $alipayRootCertPath;

    /**
     * 支付宝应用公钥证书文件路径.
     *
     * @var string
     */
    public $merchantCertPath;

    public function __construct()
    {
        $this->apiDomain = 'https://openapi.alipay.com/gateway.do';
    }
}
