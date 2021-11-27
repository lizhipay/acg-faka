<?php

namespace Yurun\PaySDK\AlipayApp\Page\Params;

/**
 * 支付宝PC场景下单并支付商品详情类.
 */
class GoodsDetail
{
    /**
     * 在支付时，可点击商品名称跳转到该地址
     *
     * @var string
     */
    public $show_url;

    public function toArray()
    {
        if (null === $this->show_url)
        {
            return null;
        }

        return (array) $this;
    }
}
