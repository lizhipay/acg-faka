<?php

namespace Yurun\PaySDK;

/**
 * 微信请求类基类.
 */
abstract class WeixinRequestBaseV3 extends RequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = '';

    /**
     * Content-Type.
     *
     * @var string|null
     */
    public $_contentType = 'json';

    /**
     * 参数中是否需要带有app_id.
     *
     * @var bool
     */
    public $needAppID = false;

    /**
     * 参数中是否需要带有mch_id.
     *
     * @var bool
     */
    public $needMchID = false;

    /**
     * 参数中是否需要带有sign_type.
     *
     * @var bool
     */
    public $needSignType = false;

    /**
     * 签名类型，为null时使用publicParams设置.
     *
     * @var string
     */
    public $signType = null;

    /**
     * 参数中是否需要带有nonce_str
     * 为true时，自动带上nonce_str
     * 为false时，不带上nonce_str
     * 为字符串时，使用该字符串作为nonce_str字段名.
     *
     * @var bool|string
     */
    public $needNonceStr = false;

    public function __construct()
    {
        $this->_isSyncVerify = true;
    }
}
