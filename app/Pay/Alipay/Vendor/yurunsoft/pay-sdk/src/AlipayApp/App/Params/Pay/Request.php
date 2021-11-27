<?php

namespace Yurun\PaySDK\AlipayApp\App\Params\Pay;

use Yurun\PaySDK\AlipayRequestBase;

/**
 * 支付宝手机支付下单请求类.
 */
class Request extends AlipayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $method = 'alipay.trade.app.pay';

    /**
     * 支付宝服务器主动通知商户服务器里指定的页面http/https路径。
     *
     * @var string
     */
    public $notify_url;

    /**
     * 业务请求参数
     * 参考https://docs.open.alipay.com/204/105465/.
     *
     * @var \Yurun\PaySDK\AlipayApp\App\Params\Pay\BusinessParams
     */
    public $businessParams;

    public function __construct()
    {
        $this->businessParams = new BusinessParams();
        $this->_method = 'GET';
    }
}
