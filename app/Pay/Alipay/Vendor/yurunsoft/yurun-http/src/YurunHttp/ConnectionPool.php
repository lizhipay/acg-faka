<?php

namespace Yurun\Util\YurunHttp;

use Psr\Http\Message\UriInterface;
use Yurun\Util\YurunHttp\Handler\Contract\IConnectionManager;
use Yurun\Util\YurunHttp\Handler\Curl\CurlHttpConnectionManager;
use Yurun\Util\YurunHttp\Handler\Swoole\SwooleHttpConnectionManager;
use Yurun\Util\YurunHttp\Http\Psr7\Uri;
use Yurun\Util\YurunHttp\Pool\Config\PoolConfig;

class ConnectionPool
{
    /**
     * 是否启用连接池.
     *
     * @var bool
     */
    private static $enabled = false;

    /**
     * 连接池配置集合.
     *
     * @var PoolConfig[]
     */
    private static $connectionPoolConfigs = [];

    /**
     * 连接管理类列表.
     *
     * @var array
     */
    private static $connectionManagers = [
        CurlHttpConnectionManager::class,
        SwooleHttpConnectionManager::class,
    ];

    private function __construct()
    {
    }

    /**
     * Get 是否启用连接池.
     *
     * @return bool
     */
    public static function isEnabled()
    {
        return self::$enabled;
    }

    /**
     * 启用连接池.
     *
     * @return void
     */
    public static function enable()
    {
        self::$enabled = true;
    }

    /**
     * 禁用连接池.
     *
     * @return void
     */
    public static function disable()
    {
        self::$enabled = false;
    }

    /**
     * 设置连接池配置.
     *
     * @param string $url
     * @param int    $maxConnections
     * @param int    $waitTimeout
     *
     * @return void
     */
    public static function setConfig($url, $maxConnections = 0, $waitTimeout = 30)
    {
        if (isset(self::$connectionPoolConfigs[$url]))
        {
            $config = self::$connectionPoolConfigs[$url];
            $config->setMaxConnections($maxConnections);
            $config->setWaitTimeout($waitTimeout);
        }
        else
        {
            self::$connectionPoolConfigs[$url] = $config = new PoolConfig($url, $maxConnections, $waitTimeout);
        }
        foreach (self::$connectionManagers as $class)
        {
            /** @var IConnectionManager $connectionManager */
            $connectionManager = $class::getInstance();
            $connectionManagerConfig = $connectionManager->getConfig($url);
            if ($connectionManagerConfig)
            {
                $connectionManagerConfig->setMaxConnections($maxConnections);
                $connectionManagerConfig->setWaitTimeout($waitTimeout);
            }
            else
            {
                $connectionManager->setConfig($url, $maxConnections, $waitTimeout);
            }
        }
    }

    /**
     * 获取连接池配置.
     *
     * @param string $url
     *
     * @return PoolConfig|null
     */
    public static function getConfig($url)
    {
        if (isset(self::$connectionPoolConfigs[$url]))
        {
            return self::$connectionPoolConfigs[$url];
        }
        else
        {
            return null;
        }
    }

    /**
     * 获取键.
     *
     * @param string|UriInterface $url
     *
     * @return string
     */
    public static function getKey($url)
    {
        if ($url instanceof UriInterface)
        {
            return $url->getScheme() . '://' . Uri::getDomain($url);
        }
        else
        {
            return $url;
        }
    }

    /**
     * Get 连接管理类列表.
     *
     * @return array
     */
    public static function getConnectionManagers()
    {
        return self::$connectionManagers;
    }

    /**
     * Set 连接管理类列表.
     *
     * @param array $connectionManagers 连接管理类列表
     *
     * @return void
     */
    public static function setConnectionManagers(array $connectionManagers)
    {
        self::$connectionManagers = $connectionManagers;
    }
}
