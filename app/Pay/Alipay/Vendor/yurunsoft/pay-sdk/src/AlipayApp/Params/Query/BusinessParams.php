<?php

namespace Yurun\PaySDK\AlipayApp\Params\Query;

/**
 * 支付宝统一收单线下交易查询业务参数类.
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
}
