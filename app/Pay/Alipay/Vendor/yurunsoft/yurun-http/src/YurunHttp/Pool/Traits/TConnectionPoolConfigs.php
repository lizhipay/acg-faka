<?php

namespace Yurun\Util\YurunHttp\Pool\Traits;

use Yurun\Util\YurunHttp\Pool\Config\PoolConfig;

trait TConnectionPoolConfigs
{
    /**
     * 连接池配置集合.
     *
     * @var PoolConfig[]
     */
    private $connectionPoolConfigs = [];

    /**
     * 设置连接池配置.
     *
     * @param string $url
     * @param int    $maxConnections
     * @param int    $waitTimeout
     *
     * @return PoolConfig
     */
    public function setConfig($url, $maxConnections = 0, $waitTimeout = 30)
    {
        if (isset($this->connectionPoolConfigs[$url]))
        {
            $config = $this->connectionPoolConfigs[$url];
            $config->setMaxConnections($maxConnections);
            $config->setWaitTimeout($waitTimeout);

            return $config;
        }
        else
        {
            return $this->connectionPoolConfigs[$url] = new PoolConfig($url, $maxConnections, $waitTimeout);
        }
    }

    /**
     * 获取连接池配置.
     *
     * @param string $url
     *
     * @return PoolConfig|null
     */
    public function getConfig($url)
    {
        if (isset($this->connectionPoolConfigs[$url]))
        {
            return $this->connectionPoolConfigs[$url];
        }
        else
        {
            return null;
        }
    }
}
