<?php

namespace Yurun\PaySDK\AlipayCrossBorder\Customs\Query;

use Yurun\PaySDK\AlipayRequestBase;

/**
 * 支付宝报关查询请求类.
 */
class Request extends AlipayRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $service = 'alipay.overseas.acquire.customs.query';

    /**
     * 报关请求号
     * 需要查询的商户端报关请求号，支持批量查询，多个值用英文半角逗号分隔，单次最多10个报关请求号；.
     *
     * @var string
     */
    public $out_request_no;

    public function __construct()
    {
        $this->_method = 'GET';
        $this->_isSyncVerify = true;
    }
}
