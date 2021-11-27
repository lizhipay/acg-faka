<?php

namespace Yurun\PaySDK\AlipayApp\Params\Refund;

/**
 * 支付宝统一收单交易退款接口业务参数.
 */
class BusinessParams
{
    use \Yurun\PaySDK\Traits\JSONParams;

    /**
     * 订单支付时传入的商户订单号,和支付宝交易号不能同时为空。
     * trade_no,out_trade_no如果同时存在优先取trade_no.
     *
     * @var string
     */
    public $out_trade_no;

    /**
     * 支付宝交易号，和商户订单号不能同时为空.
     *
     * @var string
     */
    public $trade_no;

    /**
     * 需要退款的金额，该金额不能大于订单金额,单位为元，支持两位小数.
     *
     * @var float
     */
    public $refund_amount;

    /**
     * 退款的原因说明.
     *
     * @var string
     */
    public $refund_reason;

    /**
     * 标识一次退款请求，同一笔交易多次退款需要保证唯一，如需部分退款，则此参数必传。
     *
     * @var string
     */
    public $out_request_no;

    /**
     * 商户的操作员编号.
     *
     * @var string
     */
    public $operator_id;

    /**
     * 商户的门店编号.
     *
     * @var string
     */
    public $store_id;

    /**
     * 商户的终端编号.
     *
     * @var string
     */
    public $terminal_id;
}
