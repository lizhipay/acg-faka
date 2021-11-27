<?php

namespace Yurun\PaySDK\AlipayApp\FTF\Params\QR;

use Yurun\PaySDK\AlipayApp\FTF\Params\ExtendParams;

/**
 * 支付宝统一收单线下交易预创建（扫码支付）业务参数类.
 */
class BusinessParams
{
    use \Yurun\PaySDK\Traits\JSONParams;

    /**
     * 商户订单号，64个字符以内、可包含字母、数字、下划线；需保证在商户端不重复.
     *
     * @var string
     */
    public $out_trade_no;

    /**
     * 订单总金额，单位为元，精确到小数点后两位，取值范围[0.01,100000000].
     *
     * @var float
     */
    public $total_amount = 0;

    /**
     * 参与优惠计算的金额，单位为元，精确到小数点后两位，取值范围[0.01,100000000]。
     * 如果该值未传入，但传入了【订单总金额】和【不可打折金额】，则该值默认为【订单总金额】-【不可打折金额】.
     *
     * @var float
     */
    public $discountable_amount;

    /**
     * 订单标题.
     *
     * @var string
     */
    public $subject = '';

    /**
     * 如果该值为空，则默认为商户签约账号对应的支付宝用户ID.
     *
     * @var string
     */
    public $seller_id;

    /**
     * 订单描述.
     *
     * @var string
     */
    public $body;

    /**
     * 订单包含的商品列表信息.
     *
     * @var array<\Yurun\PaySDK\AlipayApp\FTF\Params\GoodsDetail>
     */
    public $goods_detail;

    /**
     * 业务扩展参数，详见业务扩展参数说明.
     *
     * @var \Yurun\PaySDK\AlipayApp\FTF\Params\ExtendParams
     */
    public $extend_params;

    /**
     * 商户操作员编号.
     *
     * @var string
     */
    public $operator_id;

    /**
     * 商户门店编号.
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
     * 该笔订单允许的最晚付款时间，逾期将关闭交易。取值范围：1m～15d。m-分钟，h-小时，d-天，1c-当天（1c-当天的情况下，无论交易何时创建，都在0点关闭）。 该参数数值不接受小数点， 如 1.5h，可转换为 90m.
     *
     * @var string
     */
    public $timeout_express;

    public function __construct()
    {
        $this->extend_params = new ExtendParams();
    }

    public function toString()
    {
        $obj = (array) $this;
        if (empty($obj['goods_detail']))
        {
            unset($obj['goods_detail']);
        }
        else
        {
            $obj['goods_detail'] = json_encode($obj['goods_detail']);
        }
        $result = $obj['extend_params']->toArray();
        if (null === $result)
        {
            unset($obj['extend_params']);
        }
        else
        {
            $obj['extend_params'] = $result;
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
