<?php

namespace Yurun\PaySDK\AlipayApp\Fund\Query;

/**
 * 支付宝查询转账订单接口业务参数类.
 */
class BusinessParams
{
    use \Yurun\PaySDK\Traits\JSONParams;

    /**
     * 商户转账唯一订单号。发起转账来源方定义的转账单据ID，用于将转账回执通知给来源方。
     *
     * @var string
     */
    public $out_biz_no;

    /**
     * 支付宝转账单据号：和商户转账唯一订单号不能同时为空。当和商户转账唯一订单号同时提供时，将用本参数进行查询，忽略商户转账唯一订单号。
     *
     * @var string
     */
    public $order_id;
}
