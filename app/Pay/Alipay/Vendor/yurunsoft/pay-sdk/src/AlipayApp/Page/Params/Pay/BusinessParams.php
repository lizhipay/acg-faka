<?php

namespace Yurun\PaySDK\AlipayApp\Page\Params\Pay;

use Yurun\PaySDK\AlipayApp\Page\Params\GoodsDetail;

/**
 * 支付宝PC场景下单并支付业务参数类.
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
     * 销售产品码，与支付宝签约的产品码名称。 注：目前仅支持FAST_INSTANT_TRADE_PAY.
     *
     * @var string
     */
    public $product_code = 'FAST_INSTANT_TRADE_PAY';

    /**
     * 订单总金额，单位为元，精确到小数点后两位，取值范围[0.01,100000000].
     *
     * @var float
     */
    public $total_amount = 0;

    /**
     * 订单标题.
     *
     * @var string
     */
    public $subject = '';

    /**
     * 订单描述.
     *
     * @var string
     */
    public $body;

    /**
     * 订单包含的商品列表信息，Json格式： {"show_url":"https://或http://打头的商品的展示地址"} ，在支付时，可点击商品名称跳转到该地址
     *
     * @var \Yurun\PaySDK\AlipayApp\Page\Params\GoodsDetail
     */
    public $goods_detail;

    /**
     * 公用回传参数，如果请求时传递了该参数，则返回给商户时会回传该参数。支付宝只会在异步通知时将该参数原样返回。本参数必须进行UrlEncode之后才可以发送给支付宝.
     *
     * @var string
     */
    public $passback_params;

    /**
     * 业务扩展参数，详见业务扩展参数说明.
     *
     * @var \Yurun\PaySDK\AlipayApp\Page\Params\Pay\ExtendParams
     */
    public $extend_params;

    /**
     * 商品主类型：0—虚拟类商品，1—实物类商品（默认）.
     *
     * @var int
     */
    public $goods_type = 1;

    /**
     * 该笔订单允许的最晚付款时间，逾期将关闭交易。取值范围：1m～15d。m-分钟，h-小时，d-天，1c-当天（1c-当天的情况下，无论交易何时创建，都在0点关闭）。 该参数数值不接受小数点， 如 1.5h，可转换为 90m。
     * 该参数在请求到支付宝时开始计时。
     *
     * @var string
     */
    public $timeout_express;

    /**
     * 可用渠道，用户只能在指定渠道范围内支付
     * 当有多个渠道时用“,”分隔
     * 注：与disable_pay_channels互斥.
     *
     * @var string
     */
    public $enable_pay_channels;

    /**
     * 禁用渠道，用户不可用指定渠道支付
     * 当有多个渠道时用“,”分隔
     * 注：与enable_pay_channels互斥.
     *
     * @var string
     */
    public $disable_pay_channels;

    /**
     * 获取用户授权信息，可实现如免登功能。获取方法请查阅：用户信息授权.
     *
     * @var string
     */
    public $auth_token;

    /**
     * PC扫码支付的方式，支持前置模式和跳转模式。
     * 前置模式是将二维码前置到商户的订单确认页的模式。需要商户在自己的页面中以iframe方式请求支付宝页面。具体分为以下几种：
     * 0：订单码-简约前置模式，对应iframe宽度不能小于600px，高度不能小于300px；
     * 1：订单码-前置模式，对应iframe宽度不能小于300px，高度不能小于600px；
     * 3：订单码-迷你前置模式，对应iframe宽度不能小于75px，高度不能小于75px；
     * 4：订单码-可定义宽度的嵌入式二维码，商户可根据需要设定二维码的大小。
     * 跳转模式下，用户的扫码界面是由支付宝生成的，不在商户的域名下。
     * 2：订单码-跳转模式.
     *
     * @var int
     */
    public $qr_pay_mode = 2;

    /**
     * 商户自定义二维码宽度
     * 注：qr_pay_mode=4时该参数生效.
     *
     * @var int
     */
    public $qrcode_width;

    public function __construct()
    {
        $this->goods_detail = new GoodsDetail();
        $this->extend_params = new ExtendParams();
    }

    public function toString()
    {
        $obj = (array) $this;
        $result = $obj['goods_detail']->toArray();
        if (null === $result)
        {
            unset($obj['goods_detail']);
        }
        else
        {
            $obj['goods_detail'] = $result;
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
