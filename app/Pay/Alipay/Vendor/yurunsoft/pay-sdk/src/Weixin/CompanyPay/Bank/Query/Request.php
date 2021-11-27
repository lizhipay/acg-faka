<?php

namespace Yurun\PaySDK\Weixin\CompanyPay\Bank\Query;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-企业付款到银行卡查询请求类.
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'mmpaysptrans/query_bank';

    /**
     * 商户企业付款单号.
     *
     * @var string
     */
    public $partner_trade_no;

    public function __construct()
    {
        parent::__construct();
        $this->_isSyncVerify = $this->needSignType = $this->needAppID = false;
    }
}
