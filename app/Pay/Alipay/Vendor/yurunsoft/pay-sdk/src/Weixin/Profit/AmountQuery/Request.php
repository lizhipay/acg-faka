<?php

namespace Yurun\PaySDK\Weixin\Profit\AmountQuery;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-查询订单待分账金额.
 *
 * @see https://pay.weixin.qq.com/wiki/doc/api/allocation.php?chapter=27_10&index=7
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'pay/profitsharingorderamountquery';

    /**
     * 微信订单号.
     *
     * @var string
     */
    public $transaction_id;

    /**
     * 签名类型，为null时使用publicParams设置.
     *
     * @var string
     */
    public $signType = 'HMAC-SHA256';

    public function __construct()
    {
        parent::__construct();
        $this->needAppID = false;
    }
}
