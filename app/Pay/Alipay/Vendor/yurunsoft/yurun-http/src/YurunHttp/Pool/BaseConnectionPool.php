<?php

namespace Yurun\Util\YurunHttp\Pool;

use Yurun\Util\YurunHttp\Pool\Contract\IConnectionPool;

abstract class BaseConnectionPool implements IConnectionPool
{
    /**
     * 连接池配置.
     *
     * @var \Yurun\Util\YurunHttp\Pool\Config\PoolConfig
     */
    protected $config;

    /**
     * @param \Yurun\Util\YurunHttp\Pool\Config\PoolConfig $config
     */
    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * 获取连接池配置.
     *
     * @return \Yurun\Util\YurunHttp\Pool\Config\PoolConfig
     */
    public function getConfig()
    {
        return $this->config;
    }
}
