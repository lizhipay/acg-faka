<?php

namespace Yurun\PaySDK\AlipayCrossBorder\Online\NotifyVerify;

use Yurun\PaySDK\AlipayRequestBase;

/**
 * 支付宝境外支付通知验证请求类.
 */
class Request extends AlipayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $service = 'notify_verify';

    /**
     * 支付宝通知流水号，境外商户可以用这个流水号询问支付宝该条通知的合法性.
     *
     * @var string
     */
    public $notify_id;

    public function __construct()
    {
        $this->_method = 'GET';
    }
}
