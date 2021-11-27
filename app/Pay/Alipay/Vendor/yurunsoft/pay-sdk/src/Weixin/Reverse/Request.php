<?php

namespace Yurun\PaySDK\Weixin\Reverse;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-撤销订单请求类.
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'secapi/pay/reverse';

    /**
     * 微信订单号，与商户订单号二选一
     *
     * @var string
     */
    public $transaction_id;

    /**
     * 商户订单号与微信订单号二选一
     *
     * @var string
     */
    public $out_trade_no;
}
