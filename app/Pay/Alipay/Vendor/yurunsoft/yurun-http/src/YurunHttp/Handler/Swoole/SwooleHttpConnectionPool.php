<?php

namespace Yurun\Util\YurunHttp\Handler\Swoole;

use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;
use Yurun\Util\YurunHttp\Http\Psr7\Uri;
use Yurun\Util\YurunHttp\Pool\BaseConnectionPool;

class SwooleHttpConnectionPool extends BaseConnectionPool
{
    /**
     * 队列.
     *
     * @var Channel
     */
    protected $channel;

    /**
     * 连接数组.
     *
     * @var Client[]
     */
    protected $connections = [];

    /**
     * @param \Yurun\Util\YurunHttp\Pool\Config\PoolConfig $config
     */
    public function __construct($config)
    {
        parent::__construct($config);
        $this->channel = new Channel(1024);
    }

    /**
     * 关闭连接池和连接池中的连接.
     *
     * @return void
     */
    public function close()
    {
        $connections = $this->connections;
        $this->connections = [];
        $this->channel = new Channel(1024);
        foreach ($connections as $connection)
        {
            $connection->close();
        }
    }

    /**
     * 创建一个连接，但不受连接池管理.
     *
     * @return mixed
     */
    public function createConnection()
    {
        $config = $this->config;
        $uri = new Uri($config->getUrl());
        $scheme = $uri->getScheme();

        return new Client($uri->getHost(), Uri::getServerPort($uri), 'https' === $scheme || 'wss' === $scheme);
    }

    /**
     * 获取连接.
     *
     * @return mixed
     */
    public function getConnection()
    {
        $config = $this->getConfig();
        $maxConnections = $this->getConfig()->getMaxConnections();
        if ($this->getFree() > 0 || (0 != $maxConnections && $this->getCount() >= $maxConnections))
        {
            return $this->channel->pop($config->getWaitTimeout());
        }
        else
        {
            return $this->connections[] = $this->createConnection();
        }
    }

    /**
     * 释放连接占用.
     *
     * @param mixed $connection
     *
     * @return void
     */
    public function release($connection)
    {
        if (\in_array($connection, $this->connections))
        {
            $this->channel->push($connection);
        }
    }

    /**
     * 获取当前池子中连接总数.
     *
     * @return int
     */
    public function getCount()
    {
        return \count($this->connections);
    }

    /**
     * 获取当前池子中空闲连接总数.
     *
     * @return int
     */
    public function getFree()
    {
        return $this->channel->length();
    }

    /**
     * 获取当前池子中正在使用的连接总数.
     *
     * @return int
     */
    public function getUsed()
    {
        return $this->getCount() - $this->getFree();
    }
}
