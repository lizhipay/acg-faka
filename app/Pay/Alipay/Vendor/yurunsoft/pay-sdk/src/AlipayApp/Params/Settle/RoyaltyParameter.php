<?php

namespace Yurun\PaySDK\AlipayApp\Params\Settle;

/**
 * 支付宝统一收单交易结算接口-分账明细信息类.
 */
class RoyaltyParameter
{
    /**
     * 分账支出方账户，类型为userId，本参数为要分账的支付宝账号对应的支付宝唯一用户号。以2088开头的纯16位数字。
     *
     * @var string
     */
    public $trans_out;

    /**
     * 分账收入方账户，类型为userId，本参数为要分账的支付宝账号对应的支付宝唯一用户号。以2088开头的纯16位数字。
     *
     * @var string
     */
    public $trans_in;

    /**
     * 分账的金额，单位为元.
     *
     * @var float
     */
    public $amount;

    /**
     * 分账信息中分账百分比。取值范围为大于0，少于或等于100的整数。
     *
     * @var float
     */
    public $amount_percentage;

    /**
     * 分账描述.
     *
     * @var string
     */
    public $desc;
}
