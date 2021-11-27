<?php

namespace Yurun\PaySDK\AlipayApp\Fund\Transfer;

/**
 * 支付宝单笔转账到支付宝账户接口业务参数类.
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
     * 收款方账户类型。可取值：
     * ALIPAY_USERID：支付宝账号对应的支付宝唯一用户号。以2088开头的16位纯数字组成。
     * ALIPAY_LOGONID：支付宝登录号，支持邮箱和手机号格式。
     *
     * @var string
     */
    public $payee_type;

    /**
     * 收款方账户。与payee_type配合使用。付款方和收款方不能是同一个账户。
     *
     * @var string
     */
    public $payee_account;

    /**
     * 转账金额，单位：元。
     * 只支持2位小数，小数点前最大支持13位，金额必须大于等于0.1元。
     * 最大转账金额以实际签约的限额为准。
     *
     * @var string
     */
    public $amount;

    /**
     * 付款方姓名（最长支持100个英文/50个汉字）。显示在收款方的账单详情页。如果该字段不传，则默认显示付款方的支付宝认证姓名或单位名称。
     *
     * @var string
     */
    public $payer_show_name;

    /**
     * 收款方真实姓名（最长支持100个英文/50个汉字）。
     * 如果本参数不为空，则会校验该账户在支付宝登记的实名是否与收款方真实姓名一致。
     *
     * @var string
     */
    public $payee_real_name;

    /**
     * 转账备注（支持200个英文/100个汉字）。
     * 当付款方为企业账户，且转账金额达到（大于等于）50000元，remark不能为空。收款方可见，会展示在收款用户的收支详情中。
     *
     * @var string
     */
    public $remark;
}
