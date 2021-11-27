<?php

namespace Yurun\Util\YurunHttp\Handler;

interface IHandler
{
    /**
     * 发送请求
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     *
     * @return void
     */
    public function send(&$request);

    /**
     * 接收请求
     *
     * @return \Yurun\Util\YurunHttp\Http\Response|null
     */
    public function recv();

    /**
     * 连接 WebSocket.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request               $request
     * @param \Yurun\Util\YurunHttp\WebSocket\IWebSocketClient $websocketClient
     *
     * @return \Yurun\Util\YurunHttp\WebSocket\IWebSocketClient
     */
    public function websocket(&$request, $websocketClient = null);

    /**
     * Get cookie 管理器.
     *
     * @return \Yurun\Util\YurunHttp\Cookie\CookieManager
     */
    public function getCookieManager();

    /**
     * 获取原始处理器对象
     *
     * @return mixed
     */
    public function getHandler();

    /**
     * 批量运行并发请求
     *
     * @param \Yurun\Util\YurunHttp\Http\Request[] $requests
     * @param float|null                           $timeout  超时时间，单位：秒。默认为 null 不限制
     *
     * @return \Yurun\Util\YurunHttp\Http\Response[]
     */
    public function coBatch($requests, $timeout = null);

    /**
     * 关闭并释放所有资源.
     *
     * @return void
     */
    public function close();
}
