<?php

namespace Yurun\PaySDK\AlipayCrossBorder\InStore\Cancel;

use Yurun\PaySDK\AlipayRequestBase;

/**
 * 支付宝境外到店支付-取消订单请求类.
 */
class Request extends AlipayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $service = 'alipay.acquire.cancel';

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
     * 商户网站的订单号.
     *
     * @var string
     */
    public $out_trade_no;

    /**
     * 支付宝的订单号.
     *
     * @var string
     */
    public $trade_no;

    public function __construct()
    {
        $this->_method = 'GET';
        $this->_isSyncVerify = true;
    }

    public function toArray()
    {
        $obj = (array) $this;
        if (empty($obj['timestamp']))
        {
            $obj['timestamp'] = round(microtime(true) * 1000);
        }

        return $obj;
    }
}
