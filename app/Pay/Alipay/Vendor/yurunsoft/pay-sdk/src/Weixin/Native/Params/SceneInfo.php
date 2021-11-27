<?php

namespace Yurun\PaySDK\Weixin\Native\Params;

use Yurun\PaySDK\Traits\JSONParams;

/**
 * 微信支付-扫码支付-场景信息类.
 */
class SceneInfo
{
    use JSONParams{
        toString as private traitToString;
    }

    /**
     * 门店唯一标识.
     *
     * @var string
     */
    public $id;

    /**
     * 门店名称.
     *
     * @var string
     */
    public $name;

    /**
     * 门店所在地行政区划码，详细见https://pay.weixin.qq.com/wiki/doc/api/download/store_adress.csv.
     *
     * @var string
     */
    public $area_code;

    /**
     * 门店详细地址
     *
     * @var string
     */
    public $address;

    public function toString()
    {
        if (null === $this->id && null === $this->name && null === $this->area_code && null === $this->address)
        {
            return null;
        }
        else
        {
            return $this->traitToString();
        }
    }
}
