<?php

namespace Yurun\PaySDK\Weixin\Profit\MultiProfitSharing;

use Yurun\PaySDK\Weixin\Profit\ProfitSharingReceiver;
use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-请求多次分账.
 *
 * @see https://pay.weixin.qq.com/wiki/doc/api/allocation.php?chapter=27_6&index=2
 * @see https://pay.weixin.qq.com/wiki/doc/api/allocation_sl.php?chapter=25_6&index=2
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'secapi/pay/multiprofitsharing';

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
     * 商户系统内部的分账单号，在商户系统内部唯一（单次分账、多次分账、完结分账应使用不同的商户分账单号），同一分账单号多次请求等同一次。只能是数字、大小写字母_-|*@
     *
     * @var string
     */
    public $out_order_no;

    /**
     * 分账接收方列表.
     *
     * 分账接收方列表，不超过50个json对象，不能设置分账方作为分账接受方
     *
     * @var ProfitSharingReceiver[]
     */
    public $receivers;

    /**
     * 签名类型，为null时使用publicParams设置.
     *
     * @var string
     */
    public $signType = 'HMAC-SHA256';

    public function toArray()
    {
        $data = get_object_vars($this);
        if (isset($data['receivers']))
        {
            $data['receivers'] = json_encode($data['receivers']);
        }

        return $data;
    }
}
