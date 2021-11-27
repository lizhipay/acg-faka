<?php

namespace Yurun\PaySDK\Weixin\CompanyPay\Weixin\Pay;

use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付-企业付款到零钱请求类.
 */
class Request extends WeixinRequestBase
{
    /**
     * 接口名称.
     *
     * @var string
     */
    public $_apiMethod = 'mmpaymkttransfers/promotion/transfers';

    /**
     * 商户账号appid.
     *
     * @var string
     */
    public $mch_appid = '';

    /**
     * 商户号.
     *
     * @var string
     */
    public $mchid = '';

    /**
     * 设备号.
     *
     * @var string
     */
    public $device_info;

    /**
     * 商户订单号.
     *
     * @var string
     */
    public $partner_trade_no;

    /**
     * 用户openid.
     *
     * @var string
     */
    public $openid;

    /**
     * 校验用户姓名选项
     * NO_CHECK：不校验真实姓名
     * FORCE_CHECK：强校验真实姓名.
     *
     * @var string
     */
    public $check_name;

    /**
     * 收款用户姓名
     * 如果check_name设置为FORCE_CHECK，则必填用户真实姓名.
     *
     * @var string
     */
    public $re_user_name;

    /**
     * 企业付款金额，单位为分.
     *
     * @var string
     */
    public $amount;

    /**
     * 企业付款描述信息.
     *
     * @var string
     */
    public $desc;

    /**
     * 调用接口的机器Ip地址
     *
     * @var string
     */
    public $spbill_create_ip;

    public function __construct()
    {
        parent::__construct();
        $this->_isSyncVerify = $this->needSignType = false;
        $this->signType = 'MD5';
    }
}
