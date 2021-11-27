<?php

namespace Yurun\PaySDK\Weixin\Reply;

use Yurun\PaySDK\Traits\XMLParams;

/**
 * 微信支付-回复通知基类.
 */
class Base
{
    use XMLParams;

    /**
     * SUCCESS/FAIL
     * SUCCESS表示商户接收通知成功并校验成功
     *
     * @var string
     */
    public $return_code = '';

    /**
     * 返回信息，如非空，为错误原因：
     * 签名失败
     * 参数格式校验错误.
     *
     * @var string
     */
    public $return_msg = '';
}
