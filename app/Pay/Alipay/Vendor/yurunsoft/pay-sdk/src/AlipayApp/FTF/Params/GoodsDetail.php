<?php

namespace Yurun\PaySDK\AlipayApp\FTF\Params;

/**
 * 支付宝当面付商品详情.
 */
class GoodsDetail
{
    /**
     * 商品的编号.
     *
     * @var string
     */
    public $goods_id;

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
     * 商品单价，单位为元.
     *
     * @var float
     */
    public $price;

    /**
     * 商品类目.
     *
     * @var string
     */
    public $goods_category;

    /**
     * 商品描述信息.
     *
     * @var string
     */
    public $body;

    /**
     * 商品的展示地址
     *
     * @var string
     */
    public $show_url;
}
