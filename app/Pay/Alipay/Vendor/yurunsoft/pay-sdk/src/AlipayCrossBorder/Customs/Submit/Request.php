<?php

namespace Yurun\PaySDK\AlipayCrossBorder\Customs\Submit;

use Yurun\PaySDK\AlipayRequestBase;

/**
 * 支付宝报关接口请求类.
 */
class Request extends AlipayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $service = 'alipay.acquire.customs';

    /**
     * 商户生成的用于唯一标识一次报关操作的业务编号。
     * 建议生成规则：yyyymmmdd型8位日期拼接4位序列号。
     *
     * @var string
     */
    public $out_request_no;

    /**
     * 该交易在支付宝系统中的交易流水号，最长64位。
     *
     * @var string
     */
    public $trade_no;

    /**
     * 商户在海关备案的编号。
     *
     * @var string
     */
    public $merchant_customs_code;

    /**
     * 报关金额，单位为人民币“元”，精确到小数点后2位。
     *
     * @var string
     */
    public $amount;

    /**
     * 海关编号，大小写均支持。
     *
     * @var string
     */
    public $customs_place;

    /**
     * 商户海关备案名称。
     *
     * @var string
     */
    public $merchant_customs_name;

    /**
     * 商户控制本单是否拆单报关。
     * 仅当该参数传值为T或者t时，才会触发拆单（报关海关必须支持拆单）。
     *
     * @var string
     */
    public $is_split;

    /**
     * 商户子订单号。拆单时由商户传入，且拆单时必须传入，否则会报INVALID_PARAMETER错误码。
     *
     * @var string
     */
    public $sub_out_biz_no;

    /**
     * 订购人姓名。即订购人留在商户处的姓名信息。
     *
     * @var string
     */
    public $buyer_name;

    /**
     * 订购人身份证号。即订购人留在商户处的身份证信息。
     *
     * @var string
     */
    public $buyer_id_no;

    public function __construct()
    {
        $this->_method = 'GET';
        $this->_isSyncVerify = true;
    }
}
