<?php

namespace Yurun\PaySDK\AlipayCrossBorder\InStore\BarcodePay;

use Yurun\PaySDK\AlipayRequestBase;

/**
 * 支付宝境外到店支付-扫码支付请求类.
 */
class Request extends AlipayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $service = 'alipay.acquire.overseas.spot.pay';

    /**
     * 同partner.
     *
     * @var string
     */
    public $alipay_seller_id = '';

    /**
     * 商品数量.
     *
     * @var int
     */
    public $quantity;

    /**
     * 将在交易记录的列表中显示的交易记录的名称。
     *
     * @var string
     */
    public $trans_name;

    /**
     * 你的内部订单号.
     *
     * @var string
     */
    public $partner_trans_id;

    /**
     * 用于标记交易价格的货币, 这也是结算货币支付宝结算给合作伙伴.
     *
     * @var string
     */
    public $currency;

    /**
     * 上述货币的交易金额;
     * 范围: 0.01-100000000.00。小数点后两位数。
     *
     * @var float
     */
    public $trans_amount;

    /**
     * 支付宝用户付款码
     *
     * @var string
     */
    public $buyer_identity_code;

    /**
     * 付款码类型QRcode或barcode.
     *
     * @var string
     */
    public $identity_code_type;

    /**
     * 合作伙伴系统创建交易记录的时间。
     * 格式: YYYYMMDDHHMMSS.
     *
     * @var string
     */
    public $trans_create_time;

    /**
     * 交易记录.
     *
     * @var string
     */
    public $memo;

    /**
     * 产品名称, 现在它是一个静态值, 这是强制性的.
     *
     * @var string
     */
    public $biz_product = 'OVERSEAS_MBARCODE_PAY';

    /**
     * 扩展参数.
     *
     * @var \Yurun\PaySDK\AlipayCrossBorder\InStore\BarcodePay\ExtendInfo
     */
    public $extend_info;

    public function __construct()
    {
        $this->_method = 'GET';
        $this->_isSyncVerify = true;
        $this->extend_info = new ExtendInfo();
    }

    public function toArray()
    {
        $obj = (array) $this;
        if (empty($obj['extend_info']))
        {
            unset($obj['extend_info']);
        }
        else
        {
            $obj['extend_info'] = json_encode($obj['extend_info']);
        }

        return $obj;
    }
}
