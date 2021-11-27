<?php

namespace Yurun\PaySDK\Alipay\Params\Refund;

use Yurun\PaySDK\AlipayRequestBase;

/**
 * 支付宝无密退款业务请求类
 * 注：无密退款接口权限需要联系支付宝客服申请签约.
 */
class Request extends AlipayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $service = 'refund_fastpay_by_platform_nopwd';

    /**
     * 支付宝服务器主动通知商户服务器里指定的页面http/https路径。
     *
     * @var string
     */
    public $notify_url;

    /**
     * 支付宝服务器主动通知商户网站里指定的页面http 路径，用于通知商户交易充退结果。
     *
     * @var string
     */
    public $dback_notify_url;

    /**
     * 业务请求参数
     * 参考https://docs.open.alipay.com/62/104743/.
     *
     * @var \Yurun\PaySDK\Alipay\Params\Refund\BusinessParams
     */
    public $businessParams;

    public function __construct()
    {
        $this->businessParams = new BusinessParams();
        $this->_method = 'GET';
    }
}
