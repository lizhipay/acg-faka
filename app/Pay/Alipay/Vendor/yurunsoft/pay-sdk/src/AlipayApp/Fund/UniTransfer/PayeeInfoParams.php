<?php

namespace Yurun\PaySDK\AlipayApp\Fund\UniTransfer;

class PayeeInfoParams
{
    /**
     * 参与方的唯一标识
     * @var string
     */
    public $identity;

    /**
     * 参与方的标识类型，目前支持如下类型：
     * 1、ALIPAY_USER_ID 支付宝的会员ID
     * 2、ALIPAY_LOGON_ID：支付宝登录号，支持邮箱和手机号格式
     * @var string
     */
    public $identity_type;

    /**
     * 参与方真实姓名，如果非空，将校验收款支付宝账号姓名一致性。当identity_type=ALIPAY_LOGON_ID时，本字段必填。
     * @var string
     */
    public $name;
}
