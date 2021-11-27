<?php

namespace Yurun\PaySDK\Weixin\Reply;

/**
 * 微信支付-回复扫码支付模式一通知基类.
 */
class PayMode1 extends Base
{
    /**
     * 公众账号ID.
     *
     * @var string
     */
    public $appid;

    /**
     * 商户号.
     *
     * @var string
     */
    public $mch_id;

    /**
     * 微信返回的随机字符串.
     *
     * @var string
     */
    public $nonce_str;

    /**
     * 调用统一下单接口生成的预支付ID.
     *
     * @var string
     */
    public $prepay_id;

    /**
     * SUCCESS/FAIL.
     *
     * @var string
     */
    public $result_code;

    /**
     * 当result_code为FAIL时，商户展示给用户的错误提.
     *
     * @var string
     */
    public $err_code_des;

    /**
     * 返回数据签名.
     *
     * @var string
     */
    public $sign;
}
