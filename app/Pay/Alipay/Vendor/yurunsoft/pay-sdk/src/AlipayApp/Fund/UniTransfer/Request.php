<?php

namespace Yurun\PaySDK\AlipayApp\Fund\UniTransfer;

use Yurun\PaySDK\AlipayRequestBase;

class Request extends AlipayRequestBase
{
    /**
     * 接口名称
     * @link https://opendocs.alipay.com/open/01dtld
     * @var string
     */
    public $method = 'alipay.fund.trans.uni.transfer';

    /**
     * 详见：https://opendocs.alipay.com/isv/10467/xldcyq
     * @var string
     */
    public $app_auth_token;

    /**
     * 业务请求参数
     * 参考https://opendocs.alipay.com/apis/api_28/alipay.fund.trans.uni.transfer
     * @var \Yurun\PaySDK\AlipayApp\Fund\UniTransfer\BusinessParams
     */
    public $businessParams;

    public function __construct()
    {
        $this->businessParams = new BusinessParams;
        $this->_method = 'GET';
    }
}
