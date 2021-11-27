<?php

namespace Yurun\Util\YurunHttp\Pool\Config;

class PoolConfig
{
    /**
     * 地址
     *
     * @var string
     */
    protected $url;

    /**
     * 最大连接数量.
     *
     * @var int
     */
    protected $maxConnections;

    /**
     * 等待超时时间.
     *
     * @var float
     */
    protected $waitTimeout;

    /**
     * @param string $url
     * @param int    $maxConnections
     * @param float  $waitTimeout
     */
    public function __construct($url, $maxConnections, $waitTimeout)
    {
        $this->url = $url;
        $this->maxConnections = $maxConnections;
        $this->waitTimeout = $waitTimeout;
    }

    /**
     * 获取最大连接数量.
     *
     * @return int
     */
    public function getMaxConnections()
    {
        return $this->maxConnections;
    }

    /**
     * 设置最大连接数量.
     *
     * @param int $maxConnections
     *
     * @return void
     */
    public function setMaxConnections($maxConnections)
    {
        $this->maxConnections = $maxConnections;
    }

    /**
     * 获取等待超时时间.
     *
     * @return float
     */
    public function getWaitTimeout()
    {
        return $this->waitTimeout;
    }

    /**
     * 设置等待超时时间.
     *
     * @param float $waitTimeout
     *
     * @return void
     */
    public function setWaitTimeout($waitTimeout)
    {
        $this->waitTimeout = $waitTimeout;
    }

    /**
     * Get 地址
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }
}
