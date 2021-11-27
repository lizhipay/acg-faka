<?php

namespace Yurun\PaySDK\AlipayCrossBorder\InStore\BarcodePay;

/**
 * 支付宝境外到店支付-扫码支付扩展信息类.
 */
class ExtendInfo
{
    use \Yurun\PaySDK\Traits\JSONParams;

    /**
     * 将被记录在用户的声明中的子商家名称.
     *
     * @var string
     */
    public $secondary_merchant_name;

    /**
     * 用于区分每个特定子商户的子商户 ID.
     *
     * @var string
     */
    public $secondary_merchant_id;

    /**
     * 行业分类标识符
     * 参考：https://global.alipay.com/help/online/81.
     *
     * @var string
     */
    public $secondary_merchant_industry;

    /**
     * 商家指定的商户店铺的唯一 id.
     *
     * @var string
     */
    public $store_id;

    /**
     * 在客户的支付宝钱包和核对文件中显示的商家商店的名称。
     *
     * @var string
     */
    public $store_name;

    /**
     * 用于提交请求的终端 ID。如果建议使用即时升级返利, 则必须传输此参数。
     *
     * @var string
     */
    public $terminal_id;

    /**
     * 技术提供商 id。此参数用于标识付款系统提供程序。
     *
     * @var string
     */
    public $sys_service_provider_id;
}
