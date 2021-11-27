<?php

namespace Yurun\Util\YurunHttp\Handler;

use Swoole\Http2\Request as Http2Request;
use Yurun\Util\YurunHttp;
use Yurun\Util\YurunHttp\Attributes;
use Yurun\Util\YurunHttp\ConnectionPool;
use Yurun\Util\YurunHttp\Exception\WebSocketException;
use Yurun\Util\YurunHttp\Handler\Swoole\SwooleHttp2ConnectionManager;
use Yurun\Util\YurunHttp\Handler\Swoole\SwooleHttpConnectionManager;
use Yurun\Util\YurunHttp\Http\Psr7\Consts\MediaType;
use Yurun\Util\YurunHttp\Http\Psr7\Uri;
use Yurun\Util\YurunHttp\Http\Response;
use Yurun\Util\YurunHttp\Traits\TCookieManager;
use Yurun\Util\YurunHttp\Traits\THandler;

class Swoole implements IHandler
{
    use TCookieManager;
    use THandler;

    /**
     * http 连接管理器.
     *
     * @var SwooleHttpConnectionManager
     */
    private $httpConnectionManager;

    /**
     * http2 连接管理器.
     *
     * @var SwooleHttp2ConnectionManager
     */
    private $http2ConnectionManager;

    /**
     * 请求结果.
     *
     * @var \Yurun\Util\YurunHttp\Http\Response
     */
    private $result;

    /**
     * 连接池键.
     *
     * @var string
     */
    private $poolKey;

    /**
     * 连接池是否启用.
     *
     * @var bool
     */
    private $poolIsEnabled = false;

    /**
     * 本 Handler 默认的 User-Agent.
     *
     * @var string
     */
    private static $defaultUA;

    public function __construct()
    {
        if (null === static::$defaultUA)
        {
            static::$defaultUA = sprintf('Mozilla/5.0 YurunHttp/%s Swoole/%s', YurunHttp::VERSION, \defined('SWOOLE_VERSION') ? \SWOOLE_VERSION : 'unknown');
        }
        $this->initCookieManager();
        $this->httpConnectionManager = new SwooleHttpConnectionManager();
        $this->http2ConnectionManager = new SwooleHttp2ConnectionManager();
    }

    /**
     * 关闭并释放所有资源.
     *
     * @return void
     */
    public function close()
    {
        $this->httpConnectionManager->close();
        $this->http2ConnectionManager->close();
    }

    /**
     * 构建请求
     *
     * @param \Yurun\Util\YurunHttp\Http\Request                           $request
     * @param \Swoole\Coroutine\Http\Client|\Swoole\Coroutine\Http2\Client $connection
     * @param Http2Request                                                 $http2Request
     *
     * @return void
     */
    public function buildRequest($request, $connection, &$http2Request)
    {
        if ($isHttp2 = '2.0' === $request->getProtocolVersion())
        {
            $http2Request = new Http2Request();
        }
        else
        {
            $http2Request = null;
        }
        $uri = $request->getUri();
        // method
        if ($isHttp2)
        {
            $http2Request->method = $request->getMethod();
        }
        else
        {
            $connection->setMethod($request->getMethod());
        }
        // cookie
        $this->parseCookies($request, $connection, $http2Request);
        // body
        $hasFile = false;
        $redirectCount = $request->getAttribute(Attributes::PRIVATE_REDIRECT_COUNT, 0);
        if ($redirectCount <= 0)
        {
            $files = $request->getUploadedFiles();
            $body = (string) $request->getBody();
            if (!empty($files))
            {
                if ($isHttp2)
                {
                    throw new \RuntimeException('Http2 swoole handler does not support upload file');
                }
                $hasFile = true;
                foreach ($files as $name => $file)
                {
                    $connection->addFile($file->getTempFileName(), $name, $file->getClientMediaType(), basename($file->getClientFilename()));
                }
                parse_str($body, $body);
            }
            if ($isHttp2)
            {
                $http2Request->data = $body;
            }
            else
            {
                $connection->setData($body);
            }
        }
        // 其它处理
        $this->parseSSL($request);
        $this->parseProxy($request);
        $this->parseNetwork($request);
        // 设置客户端参数
        $settings = $request->getAttribute(Attributes::OPTIONS, []);
        if ($settings)
        {
            $connection->set($settings);
        }
        // headers
        if (!$request->hasHeader('Host'))
        {
            $request = $request->withHeader('Host', Uri::getDomain($uri));
        }
        if (!$hasFile && !$request->hasHeader('Content-Type'))
        {
            $request = $request->withHeader('Content-Type', MediaType::APPLICATION_FORM_URLENCODED);
        }
        if (!$request->hasHeader('User-Agent'))
        {
            $request = $request->withHeader('User-Agent', $request->getAttribute(Attributes::USER_AGENT, static::$defaultUA));
        }
        $headers = [];
        foreach ($request->getHeaders() as $name => $value)
        {
            $headers[$name] = implode(',', $value);
        }
        if ($isHttp2)
        {
            $http2Request->headers = $headers;
            $http2Request->pipeline = $request->getAttribute(Attributes::HTTP2_PIPELINE, false);
            $path = $uri->getPath();
            if ('' === $path)
            {
                $path = '/';
            }
            $query = $uri->getQuery();
            if ('' !== $query)
            {
                $path .= '?' . $query;
            }
            $http2Request->path = $path;
        }
        else
        {
            $connection->setHeaders($headers);
        }
    }

    /**
     * 发送请求
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     *
     * @return bool
     */
    public function send(&$request)
    {
        $this->poolIsEnabled = ConnectionPool::isEnabled() && false !== $request->getAttribute(Attributes::CONNECTION_POOL);
        $request = $this->sendDefer($request);
        if ($request->getAttribute(Attributes::PRIVATE_IS_HTTP2) && $request->getAttribute(Attributes::HTTP2_NOT_RECV))
        {
            return true;
        }

        return (bool) $this->recvDefer($request);
    }

    /**
     * 发送请求，但延迟接收.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     *
     * @return \Yurun\Util\YurunHttp\Http\Request
     */
    public function sendDefer($request)
    {
        $isHttp2 = '2.0' === $request->getProtocolVersion();
        if ($poolIsEnabled = $this->poolIsEnabled)
        {
            if ($isHttp2)
            {
                $http2ConnectionManager = SwooleHttp2ConnectionManager::getInstance();
            }
            else
            {
                $httpConnectionManager = SwooleHttpConnectionManager::getInstance();
            }
        }
        else
        {
            if ($isHttp2)
            {
                $http2ConnectionManager = $this->http2ConnectionManager;
            }
            else
            {
                $httpConnectionManager = $this->httpConnectionManager;
            }
        }
        if ([] !== ($queryParams = $request->getQueryParams()))
        {
            $request = $request->withUri($request->getUri()->withQuery(http_build_query($queryParams, '', '&')));
        }
        $uri = $request->getUri();
        try
        {
            $this->poolKey = $poolKey = ConnectionPool::getKey($uri);
            if ($isHttp2)
            {
                /** @var \Swoole\Coroutine\Http2\Client $connection */
                $connection = $http2ConnectionManager->getConnection($poolKey);
            }
            else
            {
                /** @var \Swoole\Coroutine\Http\Client $connection */
                $connection = $httpConnectionManager->getConnection($poolKey);
                $connection->setDefer(true);
            }
            $request = $request->withAttribute(Attributes::PRIVATE_POOL_KEY, $poolKey);
            $isWebSocket = $request->getAttribute(Attributes::PRIVATE_WEBSOCKET, false);
            // 构建
            $this->buildRequest($request, $connection, $http2Request);
            // 发送
            $path = $uri->getPath();
            if ('' === $path)
            {
                $path = '/';
            }
            $query = $uri->getQuery();
            if ('' !== $query)
            {
                $path .= '?' . $query;
            }
            if ($isWebSocket)
            {
                if ($isHttp2)
                {
                    throw new \RuntimeException('Http2 swoole handler does not support websocket');
                }
                if (!$connection->upgrade($path))
                {
                    throw new WebSocketException(sprintf('WebSocket connect faled, statusCode: %s, error: %s, errorCode: %s', $connection->statusCode, swoole_strerror($connection->errCode), $connection->errCode), $connection->errCode);
                }
            }
            elseif (null === ($saveFilePath = $request->getAttribute(Attributes::SAVE_FILE_PATH)))
            {
                if ($isHttp2)
                {
                    $result = $connection->send($http2Request);
                    $request = $request->withAttribute(Attributes::PRIVATE_HTTP2_STREAM_ID, $result);
                }
                else
                {
                    $connection->execute($path);
                }
            }
            else
            {
                if ($isHttp2)
                {
                    throw new \RuntimeException('Http2 swoole handler does not support download file');
                }
                $connection->download($path, $saveFilePath);
            }

            return $request->withAttribute(Attributes::PRIVATE_IS_HTTP2, $isHttp2)
                        ->withAttribute(Attributes::PRIVATE_IS_WEBSOCKET, $isHttp2)
                        ->withAttribute(Attributes::PRIVATE_CONNECTION, $connection);
        }
        catch (\Throwable $th)
        {
            throw $th;
        }
        finally
        {
            if ($poolIsEnabled && isset($connection) && isset($th))
            {
                if ($isHttp2)
                {
                    // @phpstan-ignore-next-line
                    $http2ConnectionManager->release($poolKey, $connection);
                }
                else
                {
                    // @phpstan-ignore-next-line
                    $httpConnectionManager->release($poolKey, $connection);
                }
            }
        }
    }

    /**
     * 延迟接收.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     * @param float|null                         $timeout
     *
     * @return \Yurun\Util\YurunHttp\Http\Response|bool
     */
    public function recvDefer($request, $timeout = null)
    {
        /** @var \Swoole\Coroutine\Http\Client|\Swoole\Coroutine\Http2\Client $connection */
        $connection = $request->getAttribute(Attributes::PRIVATE_CONNECTION);
        $poolKey = $request->getAttribute(Attributes::PRIVATE_POOL_KEY);
        $retryCount = $request->getAttribute(Attributes::PRIVATE_RETRY_COUNT, 0);
        $redirectCount = $request->getAttribute(Attributes::PRIVATE_REDIRECT_COUNT, 0);
        $isHttp2 = '2.0' === $request->getProtocolVersion();
        $isWebSocket = $request->getAttribute(Attributes::PRIVATE_WEBSOCKET, false);
        try
        {
            $this->getResponse($request, $connection, $isWebSocket, $isHttp2, $timeout);
        }
        finally
        {
            if (!$isWebSocket)
            {
                if ($isHttp2)
                {
                    if ($this->poolIsEnabled)
                    {
                        $http2ConnectionManager = SwooleHttp2ConnectionManager::getInstance();
                    }
                    else
                    {
                        $http2ConnectionManager = $this->http2ConnectionManager;
                    }
                    $http2ConnectionManager->release($poolKey, $connection);
                }
                else
                {
                    if ($this->poolIsEnabled)
                    {
                        $httpConnectionManager = SwooleHttpConnectionManager::getInstance();
                    }
                    else
                    {
                        $httpConnectionManager = $this->httpConnectionManager;
                    }
                    $httpConnectionManager->release($poolKey, $connection);
                }
            }
        }
        $result = &$this->result;
        $statusCode = $result->getStatusCode();
        // 状态码为5XX或者0才需要重试
        if ((0 === $statusCode || (5 === (int) ($statusCode / 100))) && $retryCount < $request->getAttribute(Attributes::RETRY, 0))
        {
            $request = $request->withAttribute(Attributes::RETRY, ++$retryCount);
            $deferRequest = $this->sendDefer($request);

            return $this->recvDefer($deferRequest, $timeout);
        }
        if (!$isWebSocket && $statusCode >= 300 && $statusCode < 400 && $request->getAttribute(Attributes::FOLLOW_LOCATION, true) && '' !== ($location = $result->getHeaderLine('location')))
        {
            if (++$redirectCount <= ($maxRedirects = $request->getAttribute(Attributes::MAX_REDIRECTS, 10)))
            {
                // 自己实现重定向
                $uri = $this->parseRedirectLocation($location, $request->getUri());
                if (\in_array($statusCode, [301, 302, 303]))
                {
                    $method = 'GET';
                }
                else
                {
                    $method = $request->getMethod();
                }
                $request = $request->withMethod($method)
                                   ->withUri($uri)
                                   ->withAttribute(Attributes::PRIVATE_REDIRECT_COUNT, $redirectCount);
                $deferRequest = $this->sendDefer($request);

                return $this->recvDefer($deferRequest, $timeout);
            }
            else
            {
                $result = $result->withErrno(-1)
                                 ->withError(sprintf('Maximum (%s) redirects followed', $maxRedirects));

                return false;
            }
        }
        // 下载文件名
        $savedFileName = $request->getAttribute(Attributes::SAVE_FILE_PATH);
        if (null !== $savedFileName)
        {
            $result = $result->withSavedFileName($savedFileName);
        }

        return $result;
    }

    /**
     * 连接 WebSocket.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request               $request
     * @param \Yurun\Util\YurunHttp\WebSocket\IWebSocketClient $websocketClient
     *
     * @return \Yurun\Util\YurunHttp\WebSocket\IWebSocketClient
     */
    public function websocket(&$request, $websocketClient = null)
    {
        if (!$websocketClient)
        {
            $websocketClient = new \Yurun\Util\YurunHttp\WebSocket\Swoole();
        }
        $request = $request->withAttribute(Attributes::PRIVATE_WEBSOCKET, true);
        $this->send($request);
        $websocketClient->init($this, $request, $this->result);

        return $websocketClient;
    }

    /**
     * 接收请求
     *
     * @return \Yurun\Util\YurunHttp\Http\Response|null
     */
    public function recv()
    {
        return $this->result;
    }

    /**
     * 处理cookie.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     * @param mixed                              $connection
     * @param Http2Request                       $http2Request
     *
     * @return void
     */
    private function parseCookies(&$request, $connection, $http2Request)
    {
        $cookieParams = $request->getCookieParams();
        $cookieManager = $this->cookieManager;
        foreach ($cookieParams as $name => $value)
        {
            $cookieManager->setCookie($name, $value);
        }
        $cookies = $cookieManager->getRequestCookies($request->getUri());
        if ($http2Request)
        {
            $http2Request->cookies = $cookies;
        }
        else
        {
            $connection->setCookies($cookies);
        }
    }

    /**
     * 构建 Http2 Response.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     * @param \Swoole\Coroutine\Http2\Client     $connection
     * @param \Swoole\Http2\Response|bool        $response
     *
     * @return \Yurun\Util\YurunHttp\Http\Response
     */
    public function buildHttp2Response($request, $connection, $response)
    {
        $success = false !== $response;
        $result = new Response($response->data ?? '', $success ? $response->statusCode : 0);
        if ($success)
        {
            // streamId
            $result = $result->withStreamId($response->streamId);

            // headers
            if ($response->headers)
            {
                foreach ($response->headers as $name => $value)
                {
                    $result = $result->withHeader($name, $value);
                }
            }

            // cookies
            $cookies = [];
            if (isset($response->set_cookie_headers))
            {
                $cookieManager = $this->cookieManager;
                foreach ($response->set_cookie_headers as $value)
                {
                    $cookieItem = $cookieManager->addSetCookie($value);
                    $cookies[$cookieItem->name] = (array) $cookieItem;
                }
            }
            $result = $result->withCookieOriginParams($cookies);
        }
        if ($connection)
        {
            $result = $result->withError(swoole_strerror($connection->errCode))
                             ->withErrno($connection->errCode);
        }

        return $result->withRequest($request);
    }

    /**
     * 获取响应对象
     *
     * @param \Yurun\Util\YurunHttp\Http\Request                           $request
     * @param \Swoole\Coroutine\Http\Client|\Swoole\Coroutine\Http2\Client $connection
     * @param bool                                                         $isWebSocket
     * @param bool                                                         $isHttp2
     * @param float|null                                                   $timeout
     *
     * @return \Yurun\Util\YurunHttp\Http\Response
     */
    private function getResponse($request, $connection, $isWebSocket, $isHttp2, $timeout = null)
    {
        $result = &$this->result;
        if ($isHttp2)
        {
            $response = $connection->recv($timeout);
            $result = $this->buildHttp2Response($request, $connection, $response);
        }
        else
        {
            $success = $isWebSocket ? true : $connection->recv($timeout);
            $result = new Response((string) $connection->body, $connection->statusCode);
            if ($success)
            {
                // headers
                if ($connection->headers)
                {
                    foreach ($connection->headers as $name => $value)
                    {
                        $result = $result->withHeader($name, $value);
                    }
                }

                // cookies
                $cookies = [];
                if (isset($connection->set_cookie_headers))
                {
                    foreach ($connection->set_cookie_headers as $value)
                    {
                        $cookieItem = $this->cookieManager->addSetCookie($value);
                        $cookies[$cookieItem->name] = (array) $cookieItem;
                    }
                }
                $result = $result->withCookieOriginParams($cookies);
            }
            $result = $result->withRequest($request)
                             ->withError(swoole_strerror($connection->errCode))
                             ->withErrno($connection->errCode);
        }

        return $result;
    }

    /**
     * 处理加密访问.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     *
     * @return void
     */
    private function parseSSL(&$request)
    {
        $settings = $request->getAttribute(Attributes::OPTIONS, []);
        if ($request->getAttribute(Attributes::IS_VERIFY_CA, false))
        {
            $settings['ssl_verify_peer'] = true;
            $caCert = $request->getAttribute(Attributes::CA_CERT);
            if (null !== $caCert)
            {
                $settings['ssl_cafile'] = $caCert;
            }
        }
        else
        {
            $settings['ssl_verify_peer'] = false;
        }
        $certPath = $request->getAttribute(Attributes::CERT_PATH, '');
        if ('' !== $certPath)
        {
            $settings['ssl_cert_file'] = $certPath;
        }
        $password = $request->getAttribute(Attributes::CERT_PASSWORD, '');
        if ('' === $password)
        {
            $password = $request->getAttribute(Attributes::KEY_PASSWORD, '');
        }
        if ('' !== $password)
        {
            $settings['ssl_passphrase'] = $password;
        }
        $keyPath = $request->getAttribute(Attributes::KEY_PATH, '');
        if ('' !== $keyPath)
        {
            $settings['ssl_key_file'] = $keyPath;
        }
        $request = $request->withAttribute(Attributes::OPTIONS, $settings);
    }

    /**
     * 处理代理.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     *
     * @return void
     */
    private function parseProxy(&$request)
    {
        $settings = $request->getAttribute(Attributes::OPTIONS, []);
        if ($request->getAttribute(Attributes::USE_PROXY, false))
        {
            $type = $request->getAttribute(Attributes::PROXY_TYPE);
            switch ($type)
            {
                case 'http':
                    $settings['http_proxy_host'] = $request->getAttribute(Attributes::PROXY_SERVER);
                    $port = $request->getAttribute(Attributes::PROXY_PORT);
                    if (null !== $port)
                    {
                        $settings['http_proxy_port'] = $port;
                    }
                    $settings['http_proxy_user'] = $request->getAttribute(Attributes::PROXY_USERNAME);
                    $password = $request->getAttribute(Attributes::PROXY_PASSWORD);
                    if (null !== $password)
                    {
                        $settings['http_proxy_password'] = $password;
                    }
                    break;
                case 'socks5':
                    $settings['socks5_host'] = $request->getAttribute(Attributes::PROXY_SERVER);
                    $port = $request->getAttribute(Attributes::PROXY_PORT);
                    if (null !== $port)
                    {
                        $settings['socks5_port'] = $port;
                    }
                    $settings['socks5_username'] = $request->getAttribute(Attributes::PROXY_USERNAME);
                    $password = $request->getAttribute(Attributes::PROXY_PASSWORD);
                    if (null !== $password)
                    {
                        $settings['socks5_password'] = $password;
                    }
                    break;
            }
        }
        $request = $request->withAttribute(Attributes::OPTIONS, $settings);
    }

    /**
     * 处理网络相关.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     *
     * @return void
     */
    private function parseNetwork(&$request)
    {
        $settings = $request->getAttribute(Attributes::OPTIONS, []);
        // 用户名密码认证处理
        $username = $request->getAttribute(Attributes::USERNAME);
        if (null === $username)
        {
            $uri = $request->getUri();
            $userInfo = $uri->getUserInfo();
            if ($userInfo)
            {
                $authorization = 'Basic ' . base64_encode($userInfo);
            }
            else
            {
                $authorization = null;
            }
        }
        else
        {
            $authorization = 'Basic ' . base64_encode($username . ':' . $request->getAttribute(Attributes::PASSWORD, ''));
        }
        if ($authorization)
        {
            $request = $request->withHeader('Authorization', $authorization);
        }
        // 超时
        $settings['timeout'] = $request->getAttribute(Attributes::TIMEOUT, 30000) / 1000;
        if ($settings['timeout'] < 0)
        {
            $settings['timeout'] = -1;
        }
        // 长连接
        $settings['keep_alive'] = $request->getAttribute(Attributes::KEEP_ALIVE, true);
        $request = $request->withAttribute(Attributes::OPTIONS, $settings);
    }

    /**
     * 获取原始处理器对象
     *
     * @return mixed
     */
    public function getHandler()
    {
        return null;
    }

    /**
     * Get http 连接管理器.
     *
     * @return \Yurun\Util\YurunHttp\Handler\Swoole\SwooleHttpConnectionManager
     */
    public function getSwooleHttpConnectionManager()
    {
        return $this->httpConnectionManager;
    }

    /**
     * Get http2 连接管理器.
     *
     * @return SwooleHttp2ConnectionManager
     */
    public function getHttp2ConnectionManager()
    {
        return $this->http2ConnectionManager;
    }

    /**
     * 批量运行并发请求
     *
     * @param \Yurun\Util\YurunHttp\Http\Request[] $requests
     * @param float|null                           $timeout  超时时间，单位：秒。默认为 null 不限制
     *
     * @return \Yurun\Util\YurunHttp\Http\Response[]
     */
    public function coBatch($requests, $timeout = null)
    {
        /** @var Swoole[] $handlers */
        $handlers = [];
        $results = [];
        foreach ($requests as $i => &$request)
        {
            $results[$i] = null;
            $handlers[$i] = $handler = new self();
            $request = $handler->sendDefer($request);
        }
        unset($request);
        $beginTime = microtime(true);
        $recvTimeout = null;
        foreach ($requests as $i => $request)
        {
            if (null !== $timeout)
            {
                $recvTimeout = $timeout - (microtime(true) - $beginTime);
                if ($recvTimeout <= 0)
                {
                    break;
                }
            }
            $results[$i] = $handlers[$i]->recvDefer($request, $recvTimeout);
        }

        return $results;
    }

    public function getHttpConnectionManager(): SwooleHttpConnectionManager
    {
        if (ConnectionPool::isEnabled())
        {
            return SwooleHttpConnectionManager::getInstance();
        }
        else
        {
            return $this->httpConnectionManager;
        }
    }
}
