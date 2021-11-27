<?php

namespace Yurun\PaySDK\AlipayCrossBorder\Online\WapPay;

use Yurun\PaySDK\AlipayRequestBase;

/**
 * 支付宝境外在线支付-手机网站支付请求类.
 */
class Request extends AlipayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $service = 'create_forex_trade_wap';

    /**
     * 同步返回地址，HTTP/HTTPS开头字符串.
     *
     * @var string
     */
    public $return_url;

    /**
     * 支付宝服务器主动通知商户服务器里指定的页面http/https路径。
     *
     * @var string
     */
    public $notify_url;

    /**
     * 商品的标题/交易标题/订单标题/订单关键字等。
     * 该参数最长为128个汉字。
     *
     * @var string
     */
    public $subject;

    /**
     * 对一笔交易的具体描述信息。如果是多种商品，请将商品描述字符串累加传给body。
     *
     * @var string
     */
    public $body;

    /**
     * 商户订单号，64个字符以内、可包含字母、数字、下划线；需保证在商户端不重复.
     *
     * @var string
     */
    public $out_trade_no;

    /**
     * 结算币种，如美元USD.
     *
     * @var string
     */
    public $currency;

    /**
     * 商品的外币金额，范围是0.01～1000000.00.
     *
     * @var float
     */
    public $total_fee;

    /**
     * 人民币金额，范围为0.01～1000000.00
     * 如果商户网站使用人民币进行标价就是用这个参数来替换total_fee参数，rmb_fee和total_fee不能同时使用.
     *
     * @var float
     */
    public $rmb_fee;

    /**
     * 默认12小时，最大15天。此为买家登陆到完成支付的有效时间。5m 10m 15m 30m 1h 2h 3h 5h 10h 12h 1d.
     *
     * @var string
     */
    public $timeout_rule;

    /**
     * 快捷登录返回的安全令牌。快捷登录的需要传。
     *
     * @var string
     */
    public $auth_token;

    /**
     * YYYY-MM-DD HH:MM:SS 这里请使用北京时间以便于和支付宝系统时间匹配，此参数必须要和order_valid_time参数一起使用，控制从跳转到买家登陆的有效时间.
     *
     * @var string
     */
    public $order_gmt_create;

    /**
     * 最大值为2592000，单位为秒，此参数必须要和order_gmt_create参数一起使用，控制从跳转到买家登陆的有效时间.
     *
     * @var int
     */
    public $order_valid_time;

    /**
     * 显示供货商名字.
     *
     * @var string
     */
    public $supplier;

    /**
     * 由支付机构给二级商户分配的唯一ID.
     *
     * @var string
     */
    public $secondary_merchant_id;

    /**
     * 由支付机构给二级商户分配的唯一名称.
     *
     * @var string
     */
    public $secondary_merchant_name;

    /**
     * 支付宝分配的二级商户的行业代码，参考：https://global.alipay.com/help/online/81.
     *
     * @var string
     */
    public $secondary_merchant_industry;

    /**
     * 网站支付:NEW_OVERSEAS_SELLER.
     *
     * @var string
     */
    public $product_code = 'NEW_WAP_OVERSEAS_SELLER';

    /**
     * 这个参数用来标记该笔支付是否唤起支付宝钱包来进行支付。如果支付宝钱包没有安装，则使用wap方式支付。
     *
     * @var string
     */
    public $app_pay = 'Y';

    /**
     * 分账信息.
     *
     * @var array<\Yurun\PaySDK\AlipayCrossBorder\Params\SplitFundInfo>
     */
    public $split_fund_info = [];

    public function __construct()
    {
        $this->_method = 'GET';
    }

    public function toArray()
    {
        $obj = (array) $this;
        if (empty($obj['split_fund_info']))
        {
            unset($obj['split_fund_info']);
        }
        else
        {
            $obj['split_fund_info'] = json_encode($obj['split_fund_info']);
        }

        return $obj;
    }
}
