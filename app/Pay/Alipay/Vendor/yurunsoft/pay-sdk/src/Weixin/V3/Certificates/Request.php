<?php

namespace Yurun\PaySDK\Weixin\V3\Certificates;

use Yurun\PaySDK\WeixinRequestBaseV3;

/**
 * 微信支付V3-获取平台证书列表.
 */
class Request extends WeixinRequestBaseV3
{
    /**
     * 接口请求方法.
     *
     * @var string
     */
    public $_method = 'GET';

    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'v3/certificates';

    public function __construct()
    {
        parent::__construct();
        $this->_isSyncVerify = false;
    }
}
