<?php

namespace Yurun\PaySDK\Weixin\OrderQuery;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-查询订单请求类.
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'pay/orderquery';

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
