<?php

namespace Yurun\PaySDK\Alipay\Params;

use Yurun\PaySDK\PublicBase;

/**
 * 支付宝即时到账接口公共参数.
 */
class PublicParams extends PublicBase
{
    /**
     * 商户网站使用的编码格式，如UTF-8、GBK、GB2312等。
     *
     * @var string
     */
    public $_input_charset = 'UTF-8';

    /**
     * DSA、RSA、MD5三个值可选，必须大写。
     *
     * @var string
     */
    public $sign_type = 'MD5';

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
     * MD5密钥，安全检验码，由数字和字母组成的32位字符串，查看地址：https://b.alipay.com/order/pidAndKey.htm.
     *
     * @var string
     */
    public $md5Key;

    public function __construct()
    {
        $this->apiDomain = 'https://mapi.alipay.com/gateway.do';
    }
}
