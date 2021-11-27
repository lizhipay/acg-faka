<?php

namespace Yurun\PaySDK\AlipayCrossBorder\Params;

/**
 * 支付宝境外支付-分账明细类.
 */
class SplitFundInfo
{
    use \Yurun\PaySDK\Traits\JSONParams;

    /**
     * 接受分账资金的支付宝账户ID。以2088开头的纯16位数字。
     *
     * @var string
     */
    public $transIn;

    /**
     * 分账的金额。格式必须符合相应币种的要求，比如：日元为整数，人民币最多２位小数。当分账币种是CNY时，此金额代表的是人民币；如果分账币种是外币时，此金额则是外币。但分账商户实际收到的金额始终是人民币，如果分账明细中是外币，分账商户得到的人民币实际是通过汇率进行计算得到的。数值（小数点后最多2位）.
     *
     * @var float
     */
    public $amount;

    /**
     * 分账币种。如果total_fee不为空，则分账币种必须是外币，且与结算币种一致；如果rmb_fee不为空，则分账币种必须是人民币。人民币填写“CNY”，外币请参见“币种列表”。
     *
     * @var string
     */
    public $currency;

    /**
     * 分账描述信息.
     *
     * @var string
     */
    public $desc;
}
