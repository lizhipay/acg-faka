<?php

namespace Yurun\PaySDK\Weixin\RefundQuery;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-退款查询请求类.
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'pay/refundquery';

    /**
     * 微信订单号，四选一
     *
     * @var string
     */
    public $transaction_id;

    /**
     * 商户订单号，四选一
     *
     * @var string
     */
    public $out_trade_no;

    /**
     * 商户退款单号，四选一
     *
     * @var string
     */
    public $out_refund_no;

    /**
     * 微信退款单号，四选一
     *
     * @var string
     */
    public $refund_id;
}
