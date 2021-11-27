<?php

namespace Yurun\Util\YurunHttp\Pool\Contract;

interface IConnectionPool
{
    /**
     * 关闭连接池和连接池中的连接.
     *
     * @return void
     */
    public function close();

    /**
     * 创建一个连接，但不受连接池管理.
     *
     * @return mixed
     */
    public function createConnection();

    /**
     * 获取连接.
     *
     * @return mixed
     */
    public function getConnection();

    /**
     * 释放连接占用.
     *
     * @param mixed $connection
     *
     * @return void
     */
    public function release($connection);

    /**
     * 获取当前池子中连接总数.
     *
     * @return int
     */
    public function getCount();

    /**
     * 获取当前池子中空闲连接总数.
     *
     * @return int
     */
    public function getFree();

    /**
     * 获取当前池子中正在使用的连接总数.
     *
     * @return int
     */
    public function getUsed();

    /**
     * 获取连接池配置.
     *
     * @return \Yurun\Util\YurunHttp\Pool\Config\PoolConfig
     */
    public function getConfig();
}
