<?php

namespace Yurun\PaySDK\AlipayCrossBorder\InStore\CreateMerchantQR;

/**
 * 支付宝境外到店支付-创建商户二维码渠道费配置类.
 */
class ChannelFee
{
    use \Yurun\PaySDK\Traits\JSONParams;

    /**
     * 频道费用类型,
     * FIXED: 固定金额
     * RATE: 一定百分比.
     *
     * @var string
     */
    public $type;

    /**
     * 1. 如果渠道费用类型是固定的, 价值范围是 [0, 5% 原始的订单数额]。该值的单位与结算币种一致。对于支持的小数位数, 请参阅受支持的币种。
     * 2. 如果通道费用类型为 "费率", 则值范围为 [0、0.05]。(在促销季节, 渠道费可以是0。
     *
     * @var string
     */
    public $value;
}
