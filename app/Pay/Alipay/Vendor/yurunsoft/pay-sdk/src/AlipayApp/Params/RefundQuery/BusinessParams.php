<?php

namespace Yurun\PaySDK\AlipayApp\Params\RefundQuery;

/**
 * 支付宝统一收单交易退款查询业务参数类.
 */
class BusinessParams
{
    use \Yurun\PaySDK\Traits\JSONParams;

    /**
     * 支付宝交易号，和商户订单号不能同时为空.
     *
     * @var string
     */
    public $trade_no;

    /**
     * 订单支付时传入的商户订单号,和支付宝交易号不能同时为空。
     * trade_no,out_trade_no如果同时存在优先取trade_no.
     *
     * @var string
     */
    public $out_trade_no;

    /**
     * 请求退款接口时，传入的退款请求号，如果在退款请求时未传入，则该值为创建交易时的外部交易号.
     *
     * @var string
     */
    public $out_request_no;
}
