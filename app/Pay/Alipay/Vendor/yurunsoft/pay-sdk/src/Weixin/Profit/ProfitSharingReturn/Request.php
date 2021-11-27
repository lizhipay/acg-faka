<?php

namespace Yurun\PaySDK\Weixin\Profit\ProfitSharingReturn;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-分账回退.
 *
 * @see https://pay.weixin.qq.com/wiki/doc/api/allocation.php?chapter=27_7&index=8
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'secapi/pay/profitsharingreturn';

    /**
     * 微信分账单号.
     *
     * 原发起分账请求时，微信返回的微信分账单号，与商户分账单号一一对应。微信分账单号与商户分账单号二选一填写
     *
     * @var string
     */
    public $order_id;

    /**
     * 商户分账单号.
     *
     * 原发起分账请求时使用的商户系统内部的分账单号。微信分账单号与商户分账单号二选一填写
     *
     * @var string
     */
    public $out_order_no;

    /**
     * 商户回退单号.
     *
     * 商户系统内部的回退单号，商户系统内部唯一，同一回退单号多次请求等同一次，只能是数字、大小写字母_-|*@ 。
     *
     * @var string
     */
    public $out_return_no;

    /**
     * 回退方类型.
     *
     * 枚举值：
     * MERCHANT_ID：商户号（mch_id或者sub_mch_id）
     * 暂时只支持从商户接收方回退分账金额
     *
     * @var string
     */
    public $return_account_type;

    /**
     * 回退方账号.
     *
     * 回退方类型是MERCHANT_ID时，填写商户号（mch_id或者sub_mch_id）
     * 只能对原分账请求中成功分给商户接收方进行回退
     *
     * @var string
     */
    public $return_account;

    /**
     * 回退金额.
     *
     * 需要从分账接收方回退的金额，单位为分，只能为整数，不能超过原始分账单分出给该接收方的金额
     *
     * @var int
     */
    public $return_amount;

    /**
     * 回退描述.
     *
     * @var string
     */
    public $description;

    /**
     * 签名类型，为null时使用publicParams设置.
     *
     * @var string
     */
    public $signType = 'HMAC-SHA256';
}
