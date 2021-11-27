<?php

namespace Yurun\PaySDK\Weixin\Profit\ReturnQuery;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-回退结果查询.
 *
 * @see https://pay.weixin.qq.com/wiki/doc/api/allocation.php?chapter=27_8&index=9
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'pay/profitsharingreturnquery';

    /**
     * 微信分账订单号.
     *
     * 原发起分账请求时，微信返回的微信分账单号，与商户分账单号一一对应。
     * 微信分账单号与商户分账单号二选一填写
     *
     * @var string
     */
    public $order_id;

    /**
     * 商户分账单号.
     *
     * 原发起分账请求时使用的商户系统内部的分账单号。
     * 微信分账单号与商户分账单号二选一填写
     *
     * @var string
     */
    public $out_order_no;

    /**
     * 商户回退单号.
     *
     * 调用回退接口提供的商户系统内部的回退单号
     *
     * @var string
     */
    public $out_return_no;

    /**
     * 签名类型，为null时使用publicParams设置.
     *
     * @var string
     */
    public $signType = 'HMAC-SHA256';
}
