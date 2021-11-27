<?php

namespace Yurun\PaySDK\AlipayApp\Wap\Params\Pay;

/**
 * 支付宝手机支付下单业务参数类.
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
     * 销售产品码，与支付宝签约的产品码名称。 注：目前仅支持QUICK_WAP_WAY.
     *
     * @var string
     */
    public $product_code = 'QUICK_WAP_WAY';

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
     * 公用回传参数，如果请求时传递了该参数，则返回给商户时会回传该参数。支付宝只会在异步通知时将该参数原样返回。本参数必须进行UrlEncode之后才可以发送给支付宝.
     *
     * @var string
     */
    public $passback_params;

    /**
     * 业务扩展参数，详见业务扩展参数说明.
     *
     * @var \Yurun\PaySDK\AlipayApp\Wap\Params\Pay\ExtendParams
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
     * 绝对超时时间，格式为yyyy-MM-dd HH:mm。 注：1）以支付宝系统时间为准；2）如果和timeout_express参数同时传入，以time_expire为准。
     *
     * @var string
     */
    public $time_expire;

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
     * 商户门店编号。该参数用于请求参数中以区分各门店，非必传项。
     *
     * @var string
     */
    public $store_id;

    /**
     * 添加该参数后在h5支付收银台会出现返回按钮，可用于用户付款中途退出并返回到该参数指定的商户网站地址。
     * 注：该参数对支付宝钱包标准收银台下的跳转不生效。
     *
     * @var string
     */
    public $quit_url;

    /**
     * 优惠参数
     * 注：仅与支付宝协商后可用.
     *
     * @var string
     */
    public $promo_params;

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
