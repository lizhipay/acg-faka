<?php

namespace Yurun\PaySDK\Weixin\Params;

use Yurun\PaySDK\Traits\JSONParams;

/**
 * 微信支付-商品详细描述类.
 */
class Detail
{
    use JSONParams{
        toString as private traitToString;
    }

    /**
     * 订单原价
     * 1.商户侧一张小票订单可能被分多次支付，订单原价用于记录整张小票的交易金额。
     * 2.当订单原价与支付金额不相等，则不享受优惠。
     * 3.该字段主要用于限定同一张小票多次支付，以享受多次优惠的情况，正常支付订单不必上传此参数。
     *
     * @var int
     */
    public $cost_price;

    /**
     * 商家小票ID.
     *
     * @var string
     */
    public $receipt_id;

    /**
     * 单品列表.
     *
     * @var array<\Yurun\PaySDK\Weixin\Params\GoodsDetail>
     */
    public $goods_detail = [];

    public function toString()
    {
        if (null === $this->cost_price && null === $this->receipt_id && !isset($this->goods_detail[0]))
        {
            return null;
        }
        else
        {
            return $this->traitToString();
        }
    }
}
