<?php

namespace Yurun\PaySDK\AlipayCrossBorder\InStore\Refund;

use Yurun\PaySDK\AlipayRequestBase;

/**
 * 支付宝境外到店支付-交易退款请求类.
 */
class Request extends AlipayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $service = 'alipay.acquire.overseas.spot.refund';

    /**
     * 退款通知地址，必须使用https协议.
     *
     * @var string
     */
    public $notify_url;

    /**
     * 商户网站的订单号.
     *
     * @var string
     */
    public $partner_trans_id;

    /**
     * 商户的退款单的订单号.
     *
     * @var string
     */
    public $partner_refund_id;

    /**
     * 退款金额.
     *
     * @var float
     */
    public $refund_amount;

    /**
     * 退款货币代码
     *
     * @var string
     */
    public $currency;

    /**
     * 退款原因.
     *
     * @var string
     */
    public $refund_reson;

    /**
     * 退款请求是同步或异步处理的。值: Y 或 N 默认值为 N, 异步处理。如果将值设置为 Y, notify_url 将变得毫无意义.
     *
     * @var string
     */
    public $is_sync;

    public function __construct()
    {
        $this->_method = 'GET';
        $this->_isSyncVerify = true;
    }
}
