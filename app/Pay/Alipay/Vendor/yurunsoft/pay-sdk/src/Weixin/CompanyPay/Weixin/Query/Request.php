<?php

namespace Yurun\PaySDK\Weixin\CompanyPay\Weixin\Query;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-企业付款到零钱查询请求类.
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'mmpaymkttransfers/gettransferinfo';

    /**
     * 商户订单号.
     *
     * @var string
     */
    public $partner_trade_no;

    public function __construct()
    {
        parent::__construct();
        $this->_isSyncVerify = $this->needSignType = false;
        $this->signType = 'MD5';
    }
}
