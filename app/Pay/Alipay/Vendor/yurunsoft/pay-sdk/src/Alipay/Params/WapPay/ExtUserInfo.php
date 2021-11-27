<?php

namespace Yurun\PaySDK\Alipay\Params\WapPay;

use Yurun\PaySDK\Traits\JSONParams;

class ExtUserInfo
{
    use JSONParams;

    /**
     * 证件类型
     * 填充的证件号对应的证件类型，目前支持：IDENTITY_CARD（身份证）.
     *
     * @var string
     */
    public $cert_type;

    /**
     * 证件姓名.
     *
     * @var string
     */
    public $name;

    /**
     * 证件号.
     *
     * @var string
     */
    public $cert_no;

    /**
     * 是否要进行实名制校验： l T需要 l F不需要（默认不需要）.
     *
     * @var string
     */
    public $need_check_info;
}
