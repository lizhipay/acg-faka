<?php

namespace Yurun\PaySDK\Weixin\Profit\Finish;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-完结分账.
 *
 * @see https://pay.weixin.qq.com/wiki/doc/api/allocation.php?chapter=27_5&index=6
 * @see https://pay.weixin.qq.com/wiki/doc/api/allocation_sl.php?chapter=25_5&index=6
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'secapi/pay/profitsharingfinish';

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
     * 分账完结描述.
     *
     * @var string
     */
    public $description;

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
