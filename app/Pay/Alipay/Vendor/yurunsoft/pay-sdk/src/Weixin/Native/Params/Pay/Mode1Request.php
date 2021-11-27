<?php

namespace Yurun\PaySDK\Weixin\Native\Params\Pay;

use Yurun\PaySDK\Lib\Util;
use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-扫码支付-模式1生成二维码
 * 详见https://pay.weixin.qq.com/wiki/doc/api/native.php?chapter=6_4.
 */
class Mode1Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'weixin://wxpay/bizpayurl';

    /**
     * 商户定义的商品id 或者订单号.
     *
     * @var string
     */
    public $product_id;

    /**
     * 系统当前时间.
     *
     * @var int
     */
    public $time_stamp;

    public function __construct()
    {
        $this->_method = 'GET';
        parent::__construct();
        $this->time_stamp = Util::getBeijingTime();
    }
}
