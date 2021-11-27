<?php

namespace Yurun\Util\YurunHttp\Handler\Curl;

use Yurun\Util\YurunHttp\Pool\BaseConnectionPool;

class CurlConnectionPool extends BaseConnectionPool
{
    /**
     * 队列.
     *
     * @var \SplQueue
     */
    protected $queue;

    /**
     * 连接数组.
     *
     * @var array
     */
    protected $connections = [];

    /**
     * @param \Yurun\Util\YurunHttp\Pool\Config\PoolConfig $config
     */
    public function __construct($config)
    {
        parent::__construct($config);
        $this->queue = new \SplQueue();
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
        $this->queue = new \SplQueue();
        foreach ($connections as $connection)
        {
            curl_close($connection);
        }
    }

    /**
     * 创建一个连接，但不受连接池管理.
     *
     * @return mixed
     */
    public function createConnection()
    {
        return curl_init();
    }

    /**
     * 获取连接.
     *
     * @return mixed
     */
    public function getConnection()
    {
        if ($this->getFree() > 0)
        {
            return $this->queue->dequeue();
        }
        else
        {
            $maxConnections = $this->getConfig()->getMaxConnections();
            if (0 != $maxConnections && $this->getCount() >= $maxConnections)
            {
                return false;
            }
            else
            {
                return $this->connections[] = $this->createConnection();
            }
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
            $this->queue->enqueue($connection);
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
        return $this->queue->count();
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
