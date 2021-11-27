<?php

namespace Yurun\PaySDK\Weixin\Profit\MerchantRatioQuery;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-查询订单最大分账比例(仅服务商可用).
 *
 * @see https://pay.weixin.qq.com/wiki/doc/api/allocation_sl.php?chapter=25_11&index=8
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'pay/profitsharingmerchantratioquery';

    /**
     * 品牌主商户号.
     *
     * 当服务商开通了“连锁品牌工具”后，使用品牌供应链分账时，此参数传入品牌主商户号。传入后，分账方的分账比例，校验品牌主配置的全局分账。
     * 使用普通分账，未开通“连锁品牌工具”的商户，可忽略此字段。
     *
     * @var string
     */
    public $brand_mch_id;

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
