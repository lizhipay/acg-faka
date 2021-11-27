<?php

namespace Yurun\PaySDK\Weixin\Profit;

use Yurun\PaySDK\Traits\JSONParams;

/**
 * 分账接收方.
 */
class ProfitSharingReceiver
{
    use JSONParams;

    /**
     * 分账接收方类型.
     *
     * MERCHANT_ID：商户号（mch_id或者sub_mch_id）
     * PERSONAL_OPENID：个人openid（由父商户APPID转换得到）
     * PERSONAL_SUB_OPENID: 个人sub_openid（由子商户APPID转换得到）
     *
     * @var string
     */
    public $type;

    /**
     * 分账接收方帐号.
     *
     * 类型是MERCHANT_ID时，是商户号（mch_id或者sub_mch_id）
     * 类型是PERSONAL_OPENID时，是个人openid
     * 类型是PERSONAL_SUB_OPENID时，是个人sub_openid
     *
     * @var string
     */
    public $account;

    /**
     * 分账金额.
     *
     * 分账金额，单位为分，只能为整数，不能超过原订单支付金额及最大分账比例金额
     *
     * @var int
     */
    public $amount;

    /**
     * 分账描述.
     *
     * 分账的原因描述，分账账单中需要体现
     *
     * @var string
     */
    public $description;

    /**
     * 分账个人接收方姓名.
     *
     * 可选项，在接收方类型为个人的时可选填，若有值，会检查与 name 是否实名匹配，不匹配会拒绝分账请求
     * 1、分账接收方类型是PERSONAL_OPENID时，是个人姓名（选传，传则校验）
     *
     * @var string
     */
    public $name;
}
