<?php

namespace Yurun\PaySDK\AlipayCrossBorder\Online\Notify;

use Yurun\PaySDK\NotifyBase;
use Yurun\Util\YurunHttp\Stream\MemoryStream;

/**
 * 支付宝境外支付通知基类.
 */
abstract class Base extends NotifyBase
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 返回数据.
     *
     * @param bool   $success
     * @param string $message
     *
     * @return void
     */
    public function reply($success, $message = '')
    {
        if ($success)
        {
            $result = 'success';
            if (null === $this->swooleResponse)
            {
                echo $result;
            }
            elseif ($this->swooleResponse instanceof \Swoole\Http\Response)
            {
                $this->swooleResponse->end($result);
            }
            elseif ($this->swooleResponse instanceof \Psr\Http\Message\ResponseInterface)
            {
                $this->swooleResponse = $this->swooleResponse->withBody(new MemoryStream($result));
            }
        }
    }

    /**
     * 获取通知数据.
     *
     * @return array|mixed
     */
    public function getNotifyData()
    {
        if ($this->swooleRequest instanceof \Swoole\Http\Request)
        {
            return $this->swooleRequest->post;
        }
        if ($this->swooleRequest instanceof \Psr\Http\Message\ServerRequestInterface)
        {
            return $this->swooleRequest->getParsedBody();
        }

        return $_POST;
    }

    /**
     * 对通知进行验证，是否是正确的通知.
     *
     * @return bool
     */
    public function notifyVerify()
    {
        return $this->sdk->verifyCallback($this->data);
    }
}
