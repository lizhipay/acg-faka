<?php

namespace Yurun\PaySDK\Alipay\Params\Pay;

use Yurun\PaySDK\AlipayRequestBase;

/**
 * 支付宝即时到账支付请求类.
 */
class Request extends AlipayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $service = 'create_direct_pay_by_user';

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
     * 业务请求参数
     * 参考https://docs.open.alipay.com/62/104743/.
     *
     * @var \Yurun\PaySDK\Alipay\Params\Pay\BusinessParams
     */
    public $businessParams;

    public function __construct()
    {
        $this->businessParams = new BusinessParams();
        $this->_method = 'GET';
    }
}
