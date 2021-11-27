<?php

namespace Yurun\PaySDK\Weixin\Reply;

use Yurun\PaySDK\Traits\XMLParams;

/**
 * 微信支付V3-回复通知基类.
 */
class BaseV3
{
    use XMLParams;

    /**
     * 返回状态码
     *
     * 错误码，SUCCESS为接收成功，其他错误码为失败
     *
     * @var string
     */
    public $code = '';

    /**
     * 返回信息.
     *
     * 如非空，为错误原因
     *
     * @var string
     */
    public $message = '';
}
