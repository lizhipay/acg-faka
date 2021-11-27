<?php

namespace Yurun\PaySDK\AlipayApp\Params\Refund;

use Yurun\PaySDK\AlipayRequestBase;

/**
 * 支付宝统一收单交易退款接口请求类.
 */
class Request extends AlipayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $method = 'alipay.trade.refund';

    /**
     * 详见：https://opendocs.alipay.com/isv/10467/xldcyq.
     *
     * @var string
     */
    public $app_auth_token;

    /**
     * 业务请求参数
     * 参考https://docs.open.alipay.com/api_1/alipay.trade.refund.
     *
     * @var \Yurun\PaySDK\AlipayApp\Params\Refund\BusinessParams
     */
    public $businessParams;

    public function __construct()
    {
        $this->businessParams = new BusinessParams();
        $this->_method = 'GET';
        $this->_isSyncVerify = true;
        $this->_syncResponseName = 'alipay_trade_refund_response';
    }
}
