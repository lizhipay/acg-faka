<?php

namespace Yurun\Util\YurunHttp\Handler\Curl;

use Yurun\Util\YurunHttp\ConnectionPool;
use Yurun\Util\YurunHttp\Handler\Contract\IConnectionManager;
use Yurun\Util\YurunHttp\Pool\Contract\IConnectionPool;
use Yurun\Util\YurunHttp\Pool\Traits\TConnectionPoolConfigs;

class CurlHttpConnectionManager implements IConnectionManager
{
    use TConnectionPoolConfigs;

    /**
     * 连接池集合.
     *
     * @var CurlConnectionPool[]
     */
    private $connectionPools = [];

    /**
     * @var static
     */
    private static $instance;

    /**
     * @return static
     */
    public static function getInstance()
    {
        if (null === self::$instance)
        {
            return self::$instance = new static();
        }

        return self::$instance;
    }

    /**
     * 获取连接池数组.
     *
     * @return IConnectionPool[]
     */
    public function getConnectionPools()
    {
        return $this->connectionPools;
    }

    /**
     * 获取连接池对象
     *
     * @param string $url
     *
     * @return IConnectionPool
     */
    public function getConnectionPool($url)
    {
        if (isset($this->connectionPools[$url]))
        {
            return $this->connectionPools[$url];
        }
        else
        {
            $config = ConnectionPool::getConfig($url);
            if (null === $config)
            {
                ConnectionPool::setConfig($url);
            }
            $config = ConnectionPool::getConfig($url);

            return $this->connectionPools[$url] = new CurlConnectionPool($config);
        }
    }

    /**
     * 获取连接.
     *
     * @param string $url
     *
     * @return mixed
     */
    public function getConnection($url)
    {
        $connectionPool = $this->getConnectionPool($url);

        return $connectionPool->getConnection();
    }

    /**
     * 释放连接占用.
     *
     * @param string $url
     * @param mixed  $connection
     *
     * @return void
     */
    public function release($url, $connection)
    {
        $connectionPool = $this->getConnectionPool($url);
        $connectionPool->release($connection);
    }

    /**
     * 关闭指定连接.
     *
     * @param string $url
     *
     * @return bool
     */
    public function closeConnection($url)
    {
        return false;
    }

    /**
     * 创建新连接，但不归本管理器管理.
     *
     * @param string $url
     *
     * @return mixed
     */
    public function createConnection($url)
    {
        $connectionPool = $this->getConnectionPool($url);

        return $connectionPool->createConnection();
    }

    /**
     * 关闭连接管理器.
     *
     * @return void
     */
    public function close()
    {
        $connectionPools = $this->connectionPools;
        $this->connectionPools = [];
        foreach ($connectionPools as $connectionPool)
        {
            $connectionPool->close();
        }
    }
}
