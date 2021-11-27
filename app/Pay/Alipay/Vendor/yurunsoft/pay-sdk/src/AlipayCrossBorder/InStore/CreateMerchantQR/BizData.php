<?php

namespace Yurun\PaySDK\AlipayCrossBorder\InStore\CreateMerchantQR;

/**
 * 支付宝境外到店支付-创建商户二维码业务数据类.
 */
class BizData
{
    use \Yurun\PaySDK\Traits\JSONParams;

    /**
     * 行业分类标识符
     * 参考：https://global.alipay.com/help/online/81.
     *
     * @var string
     */
    public $secondary_merchant_industry;

    /**
     * 用于区分每个特定子商户的子商户 ID.
     *
     * @var string
     */
    public $secondary_merchant_id;

    /**
     * 将被记录在用户的声明中的子商家名称.
     *
     * @var string
     */
    public $secondary_merchant_name;

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
     * 出租车操作编号.
     *
     * @var string
     */
    public $taxi_operation_id;

    /**
     * 出租车号码
     *
     * @var string
     */
    public $taxi_number;

    /**
     * 出租车司机姓名.
     *
     * @var string
     */
    public $taxi_driver_name;

    /**
     * 出租车司机手机号.
     *
     * @var string
     */
    public $taxi_driver_mobile;

    /**
     * 定价币种，货币代码
     *
     * @var string
     */
    public $trans_currency;

    /**
     * 与商人结算的货币。默认值为 CNY。如果定价币种不是 cny, 则结算币种必须是 cny 或定价币种。
     *
     * @var string
     */
    public $currency;

    /**
     * 系统服务提供程序ID.
     *
     * @var string
     */
    public $sys_service_provider_id;

    /**
     * 渠道费配置
     * 如果在创建二维码时 channel_fee 存在, 则在修改二维码时不能删除它。
     *
     * @var \Yurun\PaySDK\AlipayCrossBorder\InStore\CreateMerchantQR\ChannelFee
     */
    public $channel_fee;

    /**
     * 国家代码。请参阅 ISO 3166-1 Uor 详细信息。国家代码由两个字母 (alpha-2 代码) 组成。
     *
     * @var string
     */
    public $country_code;

    /**
     * 创建代码的存储区的地址
     *
     * @var string
     */
    public $address;

    /**
     * 付款成功后返回给商家的响应参数。商家可以定义参数
     * 最终会转为json格式.
     *
     * @var string
     */
    public $passback_parameters = [];

    /**
     * 合法的电话号码
     *
     * @var string
     */
    public $notify_mobile;

    /**
     * 合法的淘宝旺旺号码
     *
     * @var string
     */
    public $notify_wangwang;

    /**
     * 合法的支付宝账号.
     *
     * @var string
     */
    public $notify_alipay_account;

    public function __construct()
    {
        $this->channel_fee = new \Yurun\PaySDK\AlipayCrossBorder\InStore\CreateMerchantQR\ChannelFee();
    }

    public function toArray()
    {
        $obj = (array) $this;
        if (empty($obj['passback_parameters']))
        {
            unset($obj['passback_parameters']);
        }
        if (empty($obj['channel_fee']->type))
        {
            unset($obj['channel_fee']);
        }
        foreach ($obj as $key => $value)
        {
            if (null === $value)
            {
                unset($obj[$key]);
            }
        }

        return $obj;
    }
}
