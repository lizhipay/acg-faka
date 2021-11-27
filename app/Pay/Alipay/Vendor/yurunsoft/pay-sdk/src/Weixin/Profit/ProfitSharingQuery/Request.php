<?php

namespace Yurun\PaySDK\Weixin\Profit\ProfitSharingQuery;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-查询分账结果.
 *
 * @see https://pay.weixin.qq.com/wiki/doc/api/allocation.php?chapter=27_2&index=3
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'pay/profitsharingquery';

    /**
     * 微信订单号.
     *
     * @var string
     */
    public $transaction_id;

    /**
     * 商户分账单号.
     *
     * 查询分账结果，输入申请分账时的商户分账单号； 查询分账完结执行的结果，输入发起分账完结时的商户分账单号
     *
     * @var string
     */
    public $out_order_no;

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
