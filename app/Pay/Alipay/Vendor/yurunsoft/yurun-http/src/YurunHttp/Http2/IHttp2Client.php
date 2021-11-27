<?php

namespace Yurun\Util\YurunHttp\Http2;

interface IHttp2Client
{
    /**
     * @param string $host
     * @param int    $port
     * @param bool   $ssl
     * @param mixed  $handler
     */
    public function __construct($host, $port, $ssl, $handler = null);

    /**
     * 连接.
     *
     * @return bool
     */
    public function connect();

    /**
     * 获取 Http Handler.
     *
     * @return \Yurun\Util\YurunHttp\Handler\IHandler
     */
    public function getHttpHandler();

    /**
     * 关闭连接.
     *
     * @return void
     */
    public function close();

    /**
     * 发送数据
     * 成功返回streamId，失败返回false.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     * @param bool                               $pipeline         默认send方法在发送请求之后，会结束当前的Http2 Stream，启用PIPELINE后，底层会保持stream流，可以多次调用write方法，向服务器发送数据帧，请参考write方法
     * @param bool                               $dropRecvResponse 丢弃接收到的响应数据
     *
     * @return int|bool
     */
    public function send($request, $pipeline = false, $dropRecvResponse = false);

    /**
     * 向一个流写入数据帧.
     *
     * @param int    $streamId
     * @param string $data
     * @param bool   $end      是否关闭流
     *
     * @return bool
     */
    public function write($streamId, $data, $end = false);

    /**
     * 关闭一个流
     *
     * @param int $streamId
     *
     * @return bool
     */
    public function end($streamId);

    /**
     * 接收数据.
     *
     * @param int|null   $streamId 默认不传为 -1 时则监听服务端推送
     * @param float|null $timeout  超时时间，单位：秒。默认为 null 不限制
     *
     * @return \Yurun\Util\YurunHttp\Http\Response|bool
     */
    public function recv($streamId = -1, $timeout = null);

    /**
     * 是否已连接.
     *
     * @return bool
     */
    public function isConnected();

    /**
     * Get 主机名.
     *
     * @return string
     */
    public function getHost();

    /**
     * Get 端口.
     *
     * @return int
     */
    public function getPort();

    /**
     * Get 是否使用 ssl.
     *
     * @return bool
     */
    public function isSSL();

    /**
     * 获取正在接收的流数量.
     *
     * @return int
     */
    public function getRecvingCount();

    /**
     * 设置超时时间，单位：秒.
     *
     * @param float|null $timeout
     *
     * @return void
     */
    public function setTimeout($timeout);

    /**
     * 获取超时时间，单位：秒.
     *
     * @return float|null
     */
    public function getTimeout();
}
