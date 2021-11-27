<?php

namespace Yurun\PaySDK\AlipayApp\Params\DownloadBill;

/**
 * 支付宝查询对账单业务参数类.
 */
class BusinessParams
{
    use \Yurun\PaySDK\Traits\JSONParams;

    /**
     * 账单类型，商户通过接口或商户经开放平台授权后其所属服务商通过接口可以获取以下账单类型：trade、signcustomer；
     * trade指商户基于支付宝交易收单的业务账单；
     * signcustomer是指基于商户支付宝余额收入及支出等资金变动的帐务账单；.
     *
     * @var string
     */
    public $bill_type;

    /**
     * 账单时间：日账单格式为yyyy-MM-dd，月账单格式为yyyy-MM。
     *
     * @var string
     */
    public $bill_date;
}
