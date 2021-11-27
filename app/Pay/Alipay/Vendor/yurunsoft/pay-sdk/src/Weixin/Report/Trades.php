<?php

namespace Yurun\PaySDK\Weixin\Report;

use Yurun\PaySDK\Traits\JSONParams;

/**
 * 微信支付-POS机采集的交易信息类.
 */
class Trades
{
    use JSONParams;

    /**
     * 列表数据.
     *
     * @var array
     */
    public $list = [];

    public function toString()
    {
        return json_encode($this->list);
    }
}
