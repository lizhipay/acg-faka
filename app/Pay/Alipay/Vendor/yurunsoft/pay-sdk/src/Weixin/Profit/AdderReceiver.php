<?php

namespace Yurun\PaySDK\Weixin\Profit;

use Yurun\PaySDK\Traits\JSONParams;

/**
 * 分账接收方-添加分账接收方.
 */
class AdderReceiver
{
    use JSONParams;

    /**
     * 分账接收方类型.
     *
     * MERCHANT_ID：商户号（mch_id或者sub_mch_id）
     * PERSONAL_OPENID：个人openid（由父商户APPID转换得到）
     * PERSONAL_SUB_OPENID: 个人sub_openid（由子商户APPID转换得到）
     *
     * @var string
     */
    public $type;

    /**
     * 分账接收方帐号.
     *
     * 类型是MERCHANT_ID时，是商户号（mch_id或者sub_mch_id）
     * 类型是PERSONAL_OPENID时，是个人openid
     * 类型是PERSONAL_SUB_OPENID时，是个人sub_openid
     *
     * @var string
     */
    public $account;

    /**
     * 分账接收方全称.
     *
     * 分账接收方类型是MERCHANT_ID时，是商户全称（必传），当商户是小微商户或个体户时，是开户人姓名
     * 分账接收方类型是PERSONAL_OPENID时，是个人姓名（选传，传则校验）
     * 分账接收方类型是PERSONAL_SUB_OPENID时，是个人姓名（选传，传则校验）
     *
     * @var string
     */
    public $name;

    /**
     * 与分账方的关系类型.
     *
     * 如果描述的是子商户与接收方的关系。 则本字段的枚举值为：
     * SERVICE_PROVIDER：服务商
     * STORE：门店
     * STAFF：员工
     * STORE_OWNER：店主
     * PARTNER：合作伙伴
     * HEADQUARTER：总部
     * BRAND：品牌方
     * DISTRIBUTOR：分销商
     * USER：用户
     * SUPPLIER：供应商
     * CUSTOM：自定义
     *
     * 如果描述的是品牌主与接收方的关系。 则本字段的枚举值为：
     * SUPPLIER：供应商
     * DISTRIBUTOR：分销商
     * SERVICE_PROVIDER：服务商
     * PLATFORM：平台
     * STAFF：员工
     * OTHERS：其他
     *
     * @var string
     */
    public $relation_type;

    /**
     * 自定义的分账关系.
     *
     * 子商户与接收方具体的关系，本字段最多10个字。
     * 当字段relation_type的值为CUSTOM时，本字段必填
     * 当字段relation_type的值不为CUSTOM时，本字段无需填写
     *
     * @var string
     */
    public $custom_relation;
}
