<?php

namespace Yurun\PaySDK\Weixin\ExchageRate;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-汇率查询请求类.
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'pay/queryexchagerate';

    /**
     * 微信支付分配的子商户号.
     *
     * @var string
     */
    public $sub_mch_id;

    /**
     * 外币币种，详情见https://pay.weixin.qq.com/wiki/doc/api/external/native.php?chapter=4_2.
     *
     * @var string
     */
    public $fee_type;

    /**
     * 日期，格式为yyyyMMdd.
     *
     * @var string
     */
    public $date;

    public function __construct()
    {
        $this->needNonceStr = $this->needSignType = false;
        $this->signType = 'MD5';
        parent::__construct();
    }
}
