<?php

namespace Yurun\PaySDK\AlipayCrossBorder\InStore\ModifyMerchantQR;

/**
 * 支付宝境外到店支付-修改商户二维码请求类.
 */
class Request extends \Yurun\PaySDK\AlipayCrossBorder\InStore\CreateMerchantQR\Request
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $service = 'alipay.commerce.qrcode.modify';

    /**
     * 成功生成代码后返回的二维码值
     *
     * @var string
     */
    public $qrcode;
}
