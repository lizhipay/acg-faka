<?php

namespace Yurun\PaySDK\AlipayCrossBorder\InStore\PreCreate;

use Yurun\PaySDK\AlipayRequestBase;

/**
 * 支付宝境外到店支付-预创建订单请求类.
 */
class Request extends AlipayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $service = 'alipay.acquire.precreate';

    /**
     * 退款通知地址，必须使用https协议.
     *
     * @var string
     */
    public $notify_url;

    /**
     * 商户服务器发送请求的时间戳, 精确到毫秒.
     *
     * @var int
     */
    public $timestamp;

    /**
     * 终端发送请求的时间戳, 精确到毫秒。
     *
     * @var int
     */
    public $terminal_timestamp;

    /**
     * 商户订单号.
     *
     * @var string
     */
    public $out_trade_no;

    /**
     * 商品的标题/交易标题/订单标题/订单关键字等。
     *
     * @var string
     */
    public $subject;

    /**
     * 产品代码
     *
     * @var string
     */
    public $product_code = 'OVERSEAS_MBARCODE_PAY';

    /**
     * 该笔订单的资金总额，单位为RMB-Yuan。取值范围为[0.01，100000000.00]，精确到小数点后两位。
     *
     * @var string
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
     * 用于标记交易价格的货币, 这也是结算货币支付宝结算给合作伙伴.
     *
     * @var string
     */
    public $currency;

    /**
     * 定价币种，货币代码
     *
     * @var string
     */
    public $trans_currency;

    /**
     * 商品单价.
     *
     * @var string
     */
    public $price;

    /**
     * 购买数量.
     *
     * @var string
     */
    public $quantity;

    /**
     * 订单包含的商品列表信息
     * 最大允许商品数量50.
     *
     * @var array<\Yurun\PaySDK\AlipayCrossBorder\InStore\PreCreate\GoodsDetail>
     */
    public $goods_detail = [];

    /**
     * 用于传送商家的具体业务信息;如果商家和支付宝同意传输此参数并就该参数的含义达成协议, 则此参数才有效。
     * 例如, 在可以通过声波进行付款的情况下, 存储 ID 和其他信息;此类资料应以 json 格式写成;有关详细信息, 请参阅 "4.4 业务扩展参数说明"。
     *
     * @var \Yurun\PaySDK\AlipayCrossBorder\InStore\PreCreate\ExtendInfo
     */
    public $extend_params;

    /**
     * 设置逾期不付款的交易, 贸易将自动关闭一旦时间。
     * 值的范围: 1 m ~ 15 d。
     * m 分钟, h 小时, d-day, 1 c-当前天 (每当贸易被创造, 它将被关闭在 0:00)。
     * 此参数的数值 Demical 点被拒绝, 例如, 1.5h 可以 tansformed 到90m。
     * 为了实现这一功能, 支付宝需要被建议设置关闭时间。
     *
     * @var string
     */
    public $it_b_pay;

    /**
     * 如果商家通过请求字符串传输此参数, 支付宝将通过异步通知 (参数名称: extra_common_param) 来反馈此参数。
     *
     * @var string
     */
    public $passback_parameters;

    public function __construct()
    {
        $this->_method = 'GET';
        $this->_isSyncVerify = true;
        $this->goods_detail = [];
        $this->extend_params = new ExtendInfo();
    }

    public function toArray()
    {
        $obj = (array) $this;
        if (empty($obj['timestamp']))
        {
            $obj['timestamp'] = round(microtime(true) * 1000);
        }
        if (empty($obj['goods_detail']))
        {
            unset($obj['goods_detail']);
        }
        else
        {
            $obj['goods_detail'] = json_encode($obj['goods_detail']);
        }
        $obj['extend_params'] = json_encode($obj['extend_params']);

        return $obj;
    }
}
