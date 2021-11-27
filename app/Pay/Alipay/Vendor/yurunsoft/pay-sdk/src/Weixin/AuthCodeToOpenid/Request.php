<?php

namespace Yurun\PaySDK\Weixin\AuthCodeToOpenid;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-授权码查询openid请求类.
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'tools/authcodetoopenid';

    /**
     * 扫码支付授权码，设备读取用户微信中的条码或者二维码信息.
     *
     * @var string
     */
    public $auth_code;
}
