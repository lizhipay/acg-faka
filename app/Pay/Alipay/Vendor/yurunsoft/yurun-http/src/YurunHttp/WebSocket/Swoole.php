<?php

namespace Yurun\Util\YurunHttp\WebSocket;

use Yurun\Util\YurunHttp\Attributes;
use Yurun\Util\YurunHttp\Exception\WebSocketException;

class Swoole implements IWebSocketClient
{
    /**
     * Http Request.
     *
     * @var \Yurun\Util\YurunHttp\Http\Request
     */
    private $request;

    /**
     * Http Response.
     *
     * @var \Yurun\Util\YurunHttp\Http\Response
     */
    private $response;

    /**
     * Handler.
     *
     * @var \Swoole\Coroutine\Http\Client
     */
    private $handler;

    /**
     * Http Handler.
     *
     * @var \Yurun\Util\YurunHttp\Handler\Swoole
     */
    private $httpHandler;

    /**
     * 连接状态
     *
     * @var bool
     */
    private $connected = false;

    /**
     * 初始化.
     *
     * @param \Yurun\Util\YurunHttp\Handler\Swoole $httpHandler
     * @param \Yurun\Util\YurunHttp\Http\Request   $request
     * @param \Yurun\Util\YurunHttp\Http\Response  $response
     *
     * @return void
     */
    public function init($httpHandler, $request, $response)
    {
        $this->httpHandler = $httpHandler;
        $this->request = $request;
        $this->response = $response;
        $this->handler = $request->getAttribute(Attributes::PRIVATE_CONNECTION);
        $this->connected = true;
    }

    /**
     * 获取 Http Handler.
     *
     * @return \Yurun\Util\YurunHttp\Handler\IHandler
     */
    public function getHttpHandler()
    {
        return $this->httpHandler;
    }

    /**
     * 获取 Http Request.
     *
     * @return \Yurun\Util\YurunHttp\Http\Request
     */
    public function getHttpRequest()
    {
        return $this->request;
    }

    /**
     * 获取 Http Response.
     *
     * @return \Yurun\Util\YurunHttp\Http\Response
     */
    public function getHttpResponse()
    {
        return $this->response;
    }

    /**
     * 连接.
     *
     * @return bool
     */
    public function connect()
    {
        $this->httpHandler->websocket($this->request, $this);

        return $this->isConnected();
    }

    /**
     * 关闭连接.
     *
     * @return void
     */
    public function close()
    {
        $this->handler->close();
        $this->connected = true;
    }

    /**
     * 发送数据.
     *
     * @param mixed $data
     *
     * @return bool
     */
    public function send($data)
    {
        $handler = $this->handler;
        $result = $handler->push($data);
        if (!$result)
        {
            $errCode = $handler->errCode;
            throw new WebSocketException(sprintf('Send Failed, error: %s, errorCode: %s', swoole_strerror($errCode), $errCode), $errCode);
        }

        return $result;
    }

    /**
     * 接收数据.
     *
     * @param float|null $timeout 超时时间，单位：秒。默认为 null 不限制
     *
     * @return mixed
     */
    public function recv($timeout = null)
    {
        $result = $this->handler->recv($timeout);
        if (!$result)
        {
            return false;
        }

        return $result->data;
    }

    /**
     * 是否已连接.
     *
     * @return bool
     */
    public function isConnected()
    {
        return $this->connected;
    }

    /**
     * 获取错误码
     *
     * @return int
     */
    public function getErrorCode()
    {
        return $this->handler->errCode;
    }

    /**
     * 获取错误信息.
     *
     * @return string
     */
    public function getErrorMessage()
    {
        return $this->handler->errMsg;
    }

    /**
     * 获取原始客户端对象
     *
     * @return \Swoole\Coroutine\Http\Client
     */
    public function getClient()
    {
        return $this->handler;
    }
}
