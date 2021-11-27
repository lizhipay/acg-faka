<?php

namespace Yurun\Util\YurunHttp\WebSocket;

interface IWebSocketClient
{
    /**
     * 初始化.
     *
     * @param \Yurun\Util\YurunHttp\Handler\IHandler $httpHandler
     * @param \Yurun\Util\YurunHttp\Http\Request     $request
     * @param \Yurun\Util\YurunHttp\Http\Response    $response
     *
     * @return void
     */
    public function init($httpHandler, $request, $response);

    /**
     * 获取 Http Handler.
     *
     * @return \Yurun\Util\YurunHttp\Handler\IHandler
     */
    public function getHttpHandler();

    /**
     * 获取 Http Request.
     *
     * @return \Yurun\Util\YurunHttp\Http\Request
     */
    public function getHttpRequest();

    /**
     * 获取 Http Response.
     *
     * @return \Yurun\Util\YurunHttp\Http\Response
     */
    public function getHttpResponse();

    /**
     * 连接.
     *
     * @return bool
     */
    public function connect();

    /**
     * 关闭连接.
     *
     * @return void
     */
    public function close();

    /**
     * 发送数据.
     *
     * @param mixed $data
     *
     * @return bool
     */
    public function send($data);

    /**
     * 接收数据.
     *
     * @param float|null $timeout 超时时间，单位：秒。默认为 null 不限制
     *
     * @return mixed
     */
    public function recv($timeout = null);

    /**
     * 是否已连接.
     *
     * @return bool
     */
    public function isConnected();

    /**
     * 获取错误码
     *
     * @return int
     */
    public function getErrorCode();

    /**
     * 获取错误信息.
     *
     * @return string
     */
    public function getErrorMessage();

    /**
     * 获取原始客户端对象
     *
     * @return mixed
     */
    public function getClient();
}
