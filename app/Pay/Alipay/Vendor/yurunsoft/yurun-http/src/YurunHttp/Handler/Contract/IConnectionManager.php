<?php

namespace Yurun\Util\YurunHttp\Handler\Contract;

use Yurun\Util\YurunHttp\Pool\Config\PoolConfig;
use Yurun\Util\YurunHttp\Pool\Contract\IConnectionPool;

interface IConnectionManager
{
    /**
     * 获取连接池数组.
     *
     * @return IConnectionPool[]
     */
    public function getConnectionPools();

    /**
     * 获取连接池对象
     *
     * @param string $url
     *
     * @return IConnectionPool
     */
    public function getConnectionPool($url);

    /**
     * 获取连接.
     *
     * @param string $url
     *
     * @return mixed
     */
    public function getConnection($url);

    /**
     * 释放连接占用.
     *
     * @param string $url
     * @param mixed  $connection
     *
     * @return void
     */
    public function release($url, $connection);

    /**
     * 关闭指定连接.
     *
     * @param string $url
     *
     * @return bool
     */
    public function closeConnection($url);

    /**
     * 创建新连接，但不归本管理器管理.
     *
     * @param string $url
     *
     * @return mixed
     */
    public function createConnection($url);

    /**
     * 关闭连接管理器.
     *
     * @return void
     */
    public function close();

    /**
     * 设置连接池配置.
     *
     * @param string $url
     * @param int    $maxConnections
     * @param int    $waitTimeout
     *
     * @return PoolConfig
     */
    public function setConfig($url, $maxConnections = 0, $waitTimeout = 30);

    /**
     * 获取连接池配置.
     *
     * @param string $url
     *
     * @return PoolConfig|null
     */
    public function getConfig($url);
}
