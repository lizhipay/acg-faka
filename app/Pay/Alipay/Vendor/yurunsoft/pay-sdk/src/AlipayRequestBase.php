<?php

namespace Yurun\PaySDK;

/**
 * 支付宝请求基类.
 */
abstract class AlipayRequestBase extends RequestBase
{
    /**
     * 支付宝返回的json中xxx_response的名字.
     *
     * @var string
     */
    public $_syncResponseName = '';
}
