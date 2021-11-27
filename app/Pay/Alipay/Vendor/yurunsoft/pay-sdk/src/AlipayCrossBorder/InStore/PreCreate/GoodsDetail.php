<?php

namespace Yurun\PaySDK\AlipayCrossBorder\InStore\PreCreate;

/**
 * 支付宝境外到店支付-预创建订单商品详情类.
 */
class GoodsDetail
{
    /**
     * 商品ID.
     *
     * @var string
     */
    public $goodsId;

    /**
     * 商品名称.
     *
     * @var string
     */
    public $goodsName;

    /**
     * 商品分类.
     *
     * @var string
     */
    public $goodsCategory;

    /**
     * 商品链接.
     *
     * @var string
     */
    public $showUrl;

    /**
     * 数量.
     *
     * @var string
     */
    public $quantity;

    /**
     * 商品介绍.
     *
     * @var string
     */
    public $body;

    /**
     * 商品单价.
     *
     * @var string
     */
    public $price;
}
