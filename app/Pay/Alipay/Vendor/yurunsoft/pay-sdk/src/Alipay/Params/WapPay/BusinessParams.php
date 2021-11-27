<?php

namespace Yurun\PaySDK\Alipay\Params\WapPay;

/**
 * 支付宝手机网站支付接口参数类.
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
     * 钱包token
     * 接入极简版wap收银台时支持。
     * 当商户请求是来自支付宝钱包，在支付宝钱包登录后，有生成登录信息token时，使用该参数传入token将可以实现信任登录收银台，不需要再次登录。
     * 注意：
     * 登录后用户还是有入口可以切换账户，不能使用该参数锁定用户。
     *
     * @var string
     */
    public $extern_token;

    /**
     * 航旅订单其它费用
     * 航旅订单中除去票面价之外的费用，单位为RMB-Yuan。取值范围为[0.01,100000000.00]，精确到小数点后两位。
     *
     * @var float
     */
    public $otherfee;

    /**
     * 航旅订单金额
     * 航旅订单金额描述，由四项或两项构成，各项之间由“|”分隔，每项包含金额与描述，金额与描述间用“^”分隔，票面价之外的价格之和必须与otherfee相等。
     *
     * @var float
     */
    public $airticket;

    /**
     * 是否发起实名校验
     * T：发起实名校验；
     * F：不发起实名校验。
     *
     * @var string
     */
    public $rn_check;

    /**
     * 买家证件号码（需要与支付宝实名认证时所填写的证件号码一致）。
     * 说明：
     * 当scene=ZJCZTJF的情况下，才会校验buyer_cert_no字段。
     *
     * @var string
     */
    public $buyer_cert_no;

    /**
     * 买家真实姓名。
     * 说明：
     * 当scene=ZJCZTJF的情况下，才会校验buyer_real_name字段。
     *
     * @var string
     */
    public $buyer_real_name;

    /**
     * 收单场景。如需使用该字段，需向支付宝申请开通，否则传入无效。
     *
     * @var string
     */
    public $scene;

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
     * app_pay=Y：尝试唤起支付宝客户端进行支付，若用户未安装支付宝，则继续使用wap收银台进行支付。商户若为APP，则需在APP的webview中增加alipays协议处理逻辑。
     *
     * @var string
     */
    public $app_pay = 'Y';

    /**
     * 商户与支付宝约定的营销参数，为Key:Value键值对，如需使用，请联系支付宝技术人员。
     *
     * @var string
     */
    public $promo_params;

    /**
     * 业务扩展参数
     * 参数格式：参数名1^参数值1|参数名2^参数值2|……
     * 多条数据用“|”间隔。
     * 详见下面的“业务扩展参数说明”。
     *
     * @var string
     */
    public $extend_params;

    /**
     * 外部用户信息。提供给商户传入用户的身份信息，与支付宝内用户信息匹配校验。校验失败报错“付款人不匹配”。参数说明：json格式
     * 详见 https://docs.open.alipay.com/60/104790/.
     *
     * @var \Yurun\PaySDK\Alipay\Params\WapPay\ExtUserInfo
     */
    public $ext_user_info;

    public function __construct()
    {
        $this->ext_user_info = new ExtUserInfo();
    }

    public function toArray()
    {
        $obj = (array) $this;
        $result = $obj['ext_user_info']->toString();
        if (null === $result)
        {
            unset($obj['ext_user_info']);
        }
        else
        {
            $obj['ext_user_info'] = $result;
        }
        foreach ($obj as $key => $value)
        {
            if (null === $value)
            {
                unset($obj[$key]);
            }
        }

        return $obj;
    }
}
