<?php

namespace Yurun\PaySDK\Weixin\Refund;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-退款请求类.
 *
 * @see https://pay.weixin.qq.com/wiki/doc/api/micropay.php?chapter=9_4
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'secapi/pay/refund';

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

    /**
     * 商户退款单号.
     *
     * @var string
     */
    public $out_refund_no;

    /**
     * 订单总金额，单位为分，只能为整数.
     *
     * @var int
     */
    public $total_fee;

    /**
     * 退款总金额，订单总金额，单位为分，只能为整数.
     *
     * @var int
     */
    public $refund_fee;

    /**
     * 货币类型，符合ISO 4217标准的三位字母代码，默认人民币：CNY.
     *
     * @var string
     */
    public $refund_fee_type = 'CNY';

    /**
     * 退款原因
     * 若商户传入，会在下发给用户的退款消息中体现退款原因.
     *
     * @var string
     */
    public $refund_desc;

    /**
     * 退款资金来源
     * 仅针对老资金流商户使用
     * REFUND_SOURCE_UNSETTLED_FUNDS---未结算资金退款（默认使用未结算资金退款）
     * REFUND_SOURCE_RECHARGE_FUNDS---可用余额退款.
     *
     * @var string
     */
    public $refund_account;

    /**
     * 异步接收微信支付退款结果通知的回调地址，通知URL必须为外网可访问的url，不允许带参数
     * 如果参数中传了notify_url，则商户平台上配置的回调地址将不会生效。
     *
     * @var string
     */
    public $notify_url;
}
