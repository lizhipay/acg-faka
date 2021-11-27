<?php

namespace Yurun\PaySDK\Weixin\Params;

/**
 * 微信支付-商品详情类.
 */
class GoodsDetail
{
    /**
     * 商品编码
     *
     * @var string
     */
    public $goods_id;

    /**
     * 微信侧商品编码
     *
     * @var string
     */
    public $wxpay_goods_id;

    /**
     * 商品名称.
     *
     * @var string
     */
    public $goods_name;

    /**
     * 商品数量.
     *
     * @var int
     */
    public $quantity;

    /**
     * 商品单价.
     *
     * @var int
     */
    public $price;
}
