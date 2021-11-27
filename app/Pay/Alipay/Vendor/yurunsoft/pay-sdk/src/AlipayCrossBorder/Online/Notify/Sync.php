<?php

namespace Yurun\PaySDK\AlipayCrossBorder\Online\Notify;

/**
 * 支付宝境外支付同步通知类.
 */
abstract class Sync extends Base
{
    /**
     * 获取通知数据.
     *
     * @return array|mixed
     */
    public function getNotifyData()
    {
        if ($this->swooleRequest instanceof \Swoole\Http\Request)
        {
            return $this->swooleRequest->get;
        }
        if ($this->swooleRequest instanceof \Psr\Http\Message\ServerRequestInterface)
        {
            return $this->swooleRequest->getQueryParams();
        }

        return $_GET;
    }
}
