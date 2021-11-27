<?php

namespace Yurun\PaySDK\Alipay\Params\Pay;

/**
 * 支付宝即时到账支付业务参数类.
 */
class BusinessParams
{
    /**
     * 商户订单号，64个字符以内、可包含字母、数字、下划线；需保证在商户端不重复.
     *
     * @var string
     */
    public $out_trade_no;

    /**
     * 商品的标题/交易标题/订单标题/订单关键字等。
     * 该参数最长为128个汉字。
     *
     * @var string
     */
    public $subject;

    /**
     * 只支持取值为1（商品购买）。
     *
     * @var int
     */
    public $payment_type = 1;

    /**
     * 该笔订单的资金总额，单位为RMB-Yuan。取值范围为[0.01，100000000.00]，精确到小数点后两位。
     *
     * @var float
     */
    public $total_fee;

    /**
     * 卖家支付宝用户号.
     *
     * @var string
     */
    public $seller_id;

    /**
     * 卖家支付宝账号.
     *
     * @var string
     */
    public $seller_email;

    /**
     * 卖家支付宝账号别名.
     *
     * @var string
     */
    public $seller_account_name;

    /**
     * 买家支付宝用户号.
     *
     * @var string
     */
    public $buyer_id;

    /**
     * 买家支付宝账号.
     *
     * @var string
     */
    public $buyer_email;

    /**
     * 买家支付宝账号别名.
     *
     * @var string
     */
    public $buyer_account_name;

    /**
     * 商品单价.
     *
     * @var float
     */
    public $price;

    /**
     * 购买数量.
     *
     * @var int
     */
    public $quantity;

    /**
     * 对一笔交易的具体描述信息。如果是多种商品，请将商品描述字符串累加传给body。
     *
     * @var string
     */
    public $body;

    /**
     * 收银台页面上，商品展示的超链接。
     *
     * @var string
     */
    public $show_url;

    /**
     * 可用的支付渠道，用户只能在指定渠道范围内支付。
     * 当有多个渠道时，以“^”分隔。
     * 与disable_paymethod互斥。
     *
     * @var string
     */
    public $enable_paymethod;

    /**
     * 被禁用的支付渠道，用户不可用指定渠道支付。
     * 当有多个渠道时，以“^”分隔。
     * 与nable_paymethod互斥。
     *
     * @var string
     */
    public $disable_paymethod;

    /**
     * 防钓鱼时间戳，通过时间戳查询接口获取的加密支付宝系统时间戳。
     * 如果已申请开通防钓鱼时间戳验证，则此字段必填。
     *
     * @var string
     */
    public $anti_phishing_key;

    /**
     * 客户端IP，用户在创建交易时，该用户当前所使用机器的IP。
     * 如果商户申请后台开通防钓鱼IP地址检查选项，此字段必填，校验用。
     *
     * @var string
     */
    public $exter_invoke_ip;

    /**
     * 公用回传参数，如果用户请求时传递了该参数，则返回给商户时会回传该参数。
     *
     * @var string
     */
    public $extra_common_param;

    /**
     * 超时时间
     * 该笔订单允许的最晚付款时间，逾期将关闭交易。
     * 取值范围：1m～15d。
     * m-分钟，h-小时，d-天，1c-当天（1c-当天的情况下，无论交易何时创建，都在0点关闭）。
     * 该参数数值不接受小数点，如1.5h，可转换为90m。
     * 该参数在请求到支付宝时开始计时。
     *
     * @var string
     */
    public $it_b_pay;

    /**
     * 如果开通了快捷登录产品，则需要填写；如果没有开通，则为空。
     *
     * @var string
     */
    public $token;

    /**
     * 扫码支付的方式，支持前置模式和跳转模式。
     * 前置模式是将二维码前置到商户的订单确认页的模式。需要商户在自己的页面中以iframe方式请求支付宝页面。具体分为以下4种：
     * 0：订单码-简约前置模式，对应iframe宽度不能小于600px，高度不能小于300px；
     * 1：订单码-前置模式，对应iframe宽度不能小于300px，高度不能小于600px；
     * 3：订单码-迷你前置模式，对应iframe宽度不能小于75px，高度不能小于75px。
     * 4：订单码-可定义宽度的嵌入式二维码，商户可根据需要设定二维码的大小。
     * 跳转模式下，用户的扫码界面是由支付宝生成的，不在商户的域名下。
     * 2：订单码-跳转模式.
     *
     * @var int
     */
    public $qr_pay_mode = 2;

    /**
     * 商户自定义的二维码宽度。
     * 当qr_pay_mode=4时，该参数生效。
     *
     * @var int
     */
    public $qrcode_width;

    /**
     * 是否需要买家实名认证。
     * T表示需要买家实名认证；
     * 不传或者传其它值表示不需要买家实名认证。
     *
     * @var string
     */
    public $need_buyer_realnamed;

    /**
     * 参数格式：hb_fq_seller_percent ^卖家承担付费比例|hb_fq_num ^期数。
     * hb_fq_num：花呗分期数，比如分3期支付；
     * hb_fq_seller_percent：卖家承担收费比例，比如100代表卖家承担100%。
     * 两个参数必须一起传入。
     * 两个参数用“|”间隔。Key和value之间用“^”间隔。
     * 具体花呗分期期数和卖家承担收费比例可传入的数值请咨询支付宝。
     *
     * @var string
     */
    public $hb_fq_param;

    /**
     * 商品类型：
     * 1表示实物类商品
     * 0表示虚拟类商品
     * 如果不传，默认为实物类商品。
     *
     * @var int
     */
    public $goods_type = 1;

    /**
     * 业务扩展参数
     * 参数格式：参数名1^参数值1|参数名2^参数值2|……
     * 多条数据用“|”间隔。
     * 详见下面的“业务扩展参数说明”。
     *
     * @var string
     */
    public $extend_param;
}
