<?php

namespace Yurun\PaySDK\AlipayCrossBorder\Online\ExchageRate;

use Yurun\PaySDK\AlipayRequestBase;

/**
 * 支付宝境外在线支付-汇率查询请求类.
 */
class Request extends AlipayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $service = 'forex_rate_file';

    public function __construct()
    {
        $this->_method = 'GET';
    }
}
