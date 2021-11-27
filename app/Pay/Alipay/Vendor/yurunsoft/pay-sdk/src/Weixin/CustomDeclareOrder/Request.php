<?php

namespace Yurun\PaySDK\Weixin\CustomDeclareOrder;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-海关报关提交请求类.
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'cgi-bin/mch/customs/customdeclareorder';

    /**
     * 商户系统内部订单号，要求32个字符内，只能是数字、大小写字母_-|*@ ，且在同一个商户号下唯一。
     *
     * @var string
     */
    public $out_trade_no;

    /**
     * 微信支付返回的订单号.
     *
     * @var string
     */
    public $transaction_id;

    /**
     * 海关
     * NO 无需上报海关
     * GUANGZHOU_ZS 广州（总署版）
     * GUANGZHOU_HP_GJ 广州黄埔国检（需推送订单至黄埔国检的订单需分别推送广州（总署版）和广州黄埔国检，即需要请求两次报关接口）
     * GUANGZHOU_NS_GJ 广州南沙国检（需推送订单至南沙国检的订单需分别推送广州（总署版）和广州南沙国检，即需要请求两次报关接口）
     * HANGZHOU_ZS 杭州（总署版）
     * NINGBO 宁波
     * ZHENGZHOU_BS 郑州（保税物流中心）
     * CHONGQING 重庆
     * XIAN 西安
     * SHANGHAI_ZS 上海（总署版）
     * SHENZHEN 深圳
     * ZHENGZHOU_ZH_ZS 郑州综保（总署版）
     * TIANJIN 天津
     * BEIJING 北京.
     *
     * @var string
     */
    public $customs;

    /**
     * 商户在海关登记的备案号，customs非NO，此参数必填.
     *
     * @var string
     */
    public $mch_customs_no;

    /**
     * 关税，以分为单位，少数海关特殊要求上传该字段时需要
     *
     * @var int
     */
    public $duty;

    /**
     * 商户子订单号，如有拆单则必传.
     *
     * @var string
     */
    public $sub_order_no;

    /**
     * 币种，微信支付订单支付时使用的币种，暂只支持人民币CNY,如有拆单则必传。
     *
     * @var string
     */
    public $fee_type;

    /**
     * 应付金额
     * 子订单金额，以分为单位，不能超过原订单金额，order_fee=transport_fee+product_fee（应付金额=物流费+商品价格），如有拆单则必传。
     *
     * @var int
     */
    public $order_fee;

    /**
     * 物流费用，以分为单位，如有拆单则必传。
     *
     * @var int
     */
    public $transport_fee;

    /**
     * 商品费用，以分为单位，如有拆单则必传。
     *
     * @var int
     */
    public $product_fee;

    /**
     * 证件类型
     * 请传固定值IDCARD,暂只支持身份证，该参数是指用户信息，商户若有用户信息，可上送，系统将以商户上传的数据为准，进行海关通关报备；.
     *
     * @var string
     */
    public $cert_type;

    /**
     * 证件号码
     * 身份证号，尾号为字母X的身份证号，请大写字母X。该参数是指用户信息，商户若有用户信息，可上送，系统将以商户上传的数据为准，进行海关通关报备；.
     *
     * @var string
     */
    public $cert_id;

    /**
     * 姓名
     * 用户姓名，该参数是指用户信息，商户若有用户信息，可上送，系统将以商户上传的数据为准，进行海关通关报备；.
     *
     * @var string
     */
    public $name;

    public function __construct()
    {
        $this->needNonceStr = $this->needSignType = false;
        $this->signType = 'MD5';
        parent::__construct();
    }
}
