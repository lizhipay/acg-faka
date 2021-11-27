<?php

namespace Yurun\Util;

use Swoole\Coroutine;
use Yurun\Util\YurunHttp\Handler\IHandler;

abstract class YurunHttp
{
    /**
     * 默认处理器类.
     *
     * @var string|null
     */
    private static $defaultHandler = null;

    /**
     * 属性.
     *
     * @var array
     */
    private static $attributes = [];

    /**
     * 版本号.
     */
    const VERSION = '4.3';

    /**
     * 设置默认处理器类.
     *
     * @param string|null $class
     *
     * @return void
     */
    public static function setDefaultHandler($class)
    {
        static::$defaultHandler = $class;
    }

    /**
     * 获取默认处理器类.
     *
     * @return string|null
     */
    public static function getDefaultHandler()
    {
        return static::$defaultHandler;
    }

    /**
     * 获取处理器类.
     *
     * @return \Yurun\Util\YurunHttp\Handler\IHandler
     */
    public static function getHandler()
    {
        if (static::$defaultHandler)
        {
            $class = static::$defaultHandler;
        }
        elseif (\defined('SWOOLE_VERSION') && Coroutine::getuid() > -1)
        {
            $class = \Yurun\Util\YurunHttp\Handler\Swoole::class;
        }
        else
        {
            $class = \Yurun\Util\YurunHttp\Handler\Curl::class;
        }

        return new $class();
    }

    /**
     * 发送请求并获取结果.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request                 $request
     * @param \Yurun\Util\YurunHttp\Handler\IHandler|string|null $handlerClass
     *
     * @return \Yurun\Util\YurunHttp\Http\Response|null
     */
    public static function send($request, $handlerClass = null)
    {
        if ($handlerClass instanceof IHandler)
        {
            $handler = $handlerClass;
            $needClose = false;
        }
        else
        {
            $needClose = true;
            if (null === $handlerClass)
            {
                $handler = static::getHandler();
            }
            else
            {
                $handler = new $handlerClass();
            }
        }
        /** @var IHandler $handler */
        $time = microtime(true);
        foreach (static::$attributes as $name => $value)
        {
            if (null === $request->getAttribute($name))
            {
                $request = $request->withAttribute($name, $value);
            }
        }
        $handler->send($request);
        $response = $handler->recv();
        if (!$response)
        {
            return $response;
        }
        $response = $response->withTotalTime(microtime(true) - $time);
        if ($needClose)
        {
            $handler->close();
        }

        return $response;
    }

    /**
     * 发起 WebSocket 连接.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request            $request
     * @param \Yurun\Util\YurunHttp\Handler\IHandler|string $handlerClass
     *
     * @return \Yurun\Util\YurunHttp\WebSocket\IWebSocketClient
     */
    public static function websocket($request, $handlerClass = null)
    {
        if ($handlerClass instanceof IHandler)
        {
            $handler = $handlerClass;
        }
        elseif (null === $handlerClass)
        {
            $handler = static::getHandler();
        }
        else
        {
            $handler = new $handlerClass();
        }
        foreach (static::$attributes as $name => $value)
        {
            if (null === $request->getAttribute($name))
            {
                $request = $request->withAttribute($name, $value);
            }
        }

        return $handler->websocket($request);
    }

    /**
     * 获取所有全局属性.
     *
     * @return array
     */
    public static function getAttributes()
    {
        return static::$attributes;
    }

    /**
     * 获取全局属性值
     *
     * @param string $name
     * @param mixed  $default
     *
     * @return mixed
     */
    public static function getAttribute($name, $default = null)
    {
        if (\array_key_exists($name, static::$attributes))
        {
            return static::$attributes[$name];
        }
        else
        {
            return $default;
        }
    }

    /**
     * 设置全局属性值
     *
     * @param string $name
     * @param mixed  $value
     *
     * @return mixed
     */
    public static function setAttribute($name, $value)
    {
        static::$attributes[$name] = $value;
    }
}
