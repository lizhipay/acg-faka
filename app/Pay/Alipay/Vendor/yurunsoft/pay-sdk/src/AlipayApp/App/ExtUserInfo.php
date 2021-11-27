<?php

namespace Yurun\PaySDK\AlipayApp\App;

use Yurun\PaySDK\Traits\JSONParams;

class ExtUserInfo
{
    use JSONParams;

    /**
     * 证件姓名
     * need_check_info=T时该参数才有效.
     *
     * @var string
     */
    public $name;

    /**
     * 手机号
     * 该参数暂不校验.
     *
     * @var string
     */
    public $mobile;

    /**
     * 身份证：IDENTITY_CARD、护照：PASSPORT、军官证：OFFICER_CARD、士兵证：SOLDIER_CARD、户口本：HOKOU等。如有其它类型需要支持，请与蚂蚁金服工作人员联系。
     * 注： need_check_info=T时该参数才有效.
     *
     * @var string
     */
    public $cert_type;

    /**
     * 证件号
     * need_check_info=T时该参数才有效.
     *
     * @var string
     */
    public $cert_no;

    /**
     * 允许的最小买家年龄，买家年龄必须大于等于所传数值
     * 1. need_check_info=T时该参数才有效
     * 2. min_age为整数，必须大于等于0.
     *
     * @var int
     */
    public $min_age;

    /**
     * 是否强制校验付款人身份信息
     * T:强制校验，F：不强制.
     *
     * @var string
     */
    public $fix_buyer;

    /**
     * 是否强制校验身份信息
     * T:强制校验，F：不强制.
     *
     * @var string
     */
    public $need_check_info;
}
