<?php

namespace Yurun\PaySDK\AlipayApp\MiniApp\Params\Pay;

/**
 * 支付宝小程序支付下单业务参数类.
 */
class BusinessParams
{
    use \Yurun\PaySDK\Traits\JSONParams;

    /**
     * 订单描述.
     *
     * @var string
     */
    public $body;

    /**
     * 订单标题.
     *
     * @var string
     */
    public $subject = '';

    /**
     * 买家的支付宝唯一用户号（2088开头的16位纯数字）
     * 特殊可选.
     *
     * @var string
     */
    public $buyer_id = '';

    /**
     * 商户订单号，64个字符以内、可包含字母、数字、下划线；需保证在商户端不重复.
     *
     * @var string
     */
    public $out_trade_no;

    /**
     * 卖家支付宝用户ID。
     * 如果该值为空，则默认为商户签约账号对应的支付宝用户ID.
     *
     * @var string
     */
    public $seller_id;

    /**
     * 该笔订单允许的最晚付款时间，逾期将关闭交易。取值范围：1m～15d。m-分钟，h-小时，d-天，1c-当天（1c-当天的情况下，无论交易何时创建，都在0点关闭）。 该参数数值不接受小数点， 如 1.5h，可转换为 90m。
     * 该参数在请求到支付宝时开始计时。
     *
     * @var string
     */
    public $timeout_express;

    /**
     * 订单总金额，单位为元，精确到小数点后两位，取值范围[0.01,100000000].
     *
     * @var float
     */
    public $total_amount = 0;

    /**
     * 可打折金额.
     * 参与优惠计算的金额，单位为元，精确到小数点后两位，取值范围[0.01,100000000]
     * 如果该值未传入，但传入了【订单总金额】，【不可打折金额】则该值默认为【订单总金额】-【不可打折金额】.
     *
     * @var float
     */
    public $discountable_amount;

    /**
     * 销售产品码。
     * 如果签约的是当面付快捷版，则传OFFLINE_PAYMENT;
     * 其它支付宝当面付产品传FACE_TO_FACE_PAYMENT；
     * 不传默认使用FACE_TO_FACE_PAYMENT；.
     *
     * @var string
     */
    public $product_code = 'FACE_TO_FACE_PAYMENT';

    /**
     * 业务扩展参数，详见业务扩展参数说明.
     *
     * @var \Yurun\PaySDK\AlipayApp\MiniApp\Params\Pay\ExtendParams
     */
    public $extend_params;

    /**
     * 商户操作员编号.
     *
     * @var string
     */
    public $operator_id;

    /**
     * 商户门店编号。该参数用于请求参数中以区分各门店，非必传项。
     *
     * @var string
     */
    public $store_id;

    /**
     * 商户机具终端编号.
     *
     * @var string
     */
    public $terminal_id;

    /**
     * 描述结算信息，json格式，详见结算参数说明.
     *
     * @var array
     */
    public $settle_info;

    /**
     * 物流信息.
     *
     * @var array
     */
    public $logistics_detail;

    /**
     * 商户传入业务信息，具体值要和支付宝约定，应用于安全，营销等参数直传场景，格式为json格式.
     *
     * @var array
     */
    public $business_params;

    /**
     * 收货人及地址信息.
     *
     * @var array
     */
    public $receiver_address_info;

    public function __construct()
    {
        $this->extend_params = new ExtendParams();
    }

    public function toString()
    {
        $obj = (array) $this;
        $result = $obj['extend_params']->toArray();
        if (null === $result)
        {
            unset($obj['extend_params']);
        }
        else
        {
            $obj['extend_params'] = $result;
        }
        if (null === $obj['logistics_detail'])
        {
            unset($obj['logistics_detail']);
        }
        else
        {
            $obj['logistics_detail'] = json_encode($obj['logistics_detail']);
        }
        if (null === $obj['business_params'])
        {
            unset($obj['business_params']);
        }
        else
        {
            $obj['business_params'] = json_encode($obj['business_params']);
        }
        if (null === $obj['receiver_address_info'])
        {
            unset($obj['receiver_address_info']);
        }
        else
        {
            $obj['receiver_address_info'] = json_encode($obj['receiver_address_info']);
        }
        foreach ($obj as $key => $value)
        {
            if (null === $value)
            {
                unset($obj[$key]);
            }
        }

        return json_encode($obj);
    }
}
