<?php

namespace Yurun\Util\YurunHttp\Handler;

use Psr\Http\Message\UriInterface;
use Yurun\Util\YurunHttp;
use Yurun\Util\YurunHttp\Attributes;
use Yurun\Util\YurunHttp\ConnectionPool;
use Yurun\Util\YurunHttp\FormDataBuilder;
use Yurun\Util\YurunHttp\Handler\Curl\CurlHttpConnectionManager;
use Yurun\Util\YurunHttp\Http\Psr7\Consts\MediaType;
use Yurun\Util\YurunHttp\Http\Response;
use Yurun\Util\YurunHttp\Stream\MemoryStream;
use Yurun\Util\YurunHttp\Traits\TCookieManager;
use Yurun\Util\YurunHttp\Traits\THandler;

class Curl implements IHandler
{
    use TCookieManager;
    use THandler;

    /**
     * 请求结果.
     *
     * @var \Yurun\Util\YurunHttp\Http\Response
     */
    private $result;

    /**
     * curl 句柄.
     *
     * @var resource|null
     */
    private $handler;

    /**
     * 请求内容.
     *
     * @var \Yurun\Util\YurunHttp\Http\Request
     */
    private $request;

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
     * 代理认证方式.
     *
     * @var array
     */
    public static $proxyAuths = [
        'basic' => \CURLAUTH_BASIC,
        'ntlm'  => \CURLAUTH_NTLM,
    ];

    /**
     * 代理类型.
     *
     * @var array
     */
    public static $proxyType = [
        'http'      => \CURLPROXY_HTTP,
        'socks4'    => \CURLPROXY_SOCKS4,
        'socks4a'   => 6, // CURLPROXY_SOCKS4A
        'socks5'    => \CURLPROXY_SOCKS5,
    ];

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
            $version = curl_version();
            static::$defaultUA = sprintf('Mozilla/5.0 YurunHttp/%s Curl/%s', YurunHttp::VERSION, isset($version['version']) ? $version['version'] : 'unknown');
        }
        $this->initCookieManager();
    }

    /**
     * 关闭并释放所有资源.
     *
     * @return void
     */
    public function close()
    {
        if ($this->handler)
        {
            if ($this->poolIsEnabled)
            {
                CurlHttpConnectionManager::getInstance()->release($this->poolKey, $this->handler);
            }
            else
            {
                curl_close($this->handler);
            }
            $this->handler = null;
        }
    }

    /**
     * 发送请求
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     *
     * @return void
     */
    public function send(&$request)
    {
        $this->poolIsEnabled = $poolIsEnabled = ConnectionPool::isEnabled() && false !== $request->getAttribute(Attributes::CONNECTION_POOL);
        if ($poolIsEnabled)
        {
            $httpConnectionManager = CurlHttpConnectionManager::getInstance();
        }
        try
        {
            $this->request = $request;
            $request = &$this->request;
            $handler = &$this->handler;
            if (!$handler)
            {
                if ($poolIsEnabled)
                {
                    $this->poolKey = $poolKey = ConnectionPool::getKey($request->getUri());
                    $handler = $httpConnectionManager->getConnection($poolKey);
                }
                else
                {
                    $handler = curl_init();
                }
            }
            $files = $request->getUploadedFiles();
            $body = (string) $request->getBody();

            if (!empty($files))
            {
                $body = FormDataBuilder::build($body, $files, $boundary);
                $request = $request->withHeader('Content-Type', MediaType::MULTIPART_FORM_DATA . '; boundary=' . $boundary);
            }
            $this->buildCurlHandlerBase($request, $handler, $receiveHeaders, $saveFileFp);
            if ([] !== ($queryParams = $request->getQueryParams()))
            {
                $request = $request->withUri($request->getUri()->withQuery(http_build_query($queryParams, '', '&')));
            }
            $uri = $request->getUri();
            $isLocation = false;
            $statusCode = 0;
            $redirectCount = 0;
            do
            {
                // 请求方法
                if ($isLocation && \in_array($statusCode, [301, 302, 303]))
                {
                    $method = 'GET';
                }
                else
                {
                    $method = $request->getMethod();
                }
                if ('GET' !== $method)
                {
                    $bodyContent = $body;
                }
                else
                {
                    $bodyContent = false;
                }
                $this->buildCurlHandlerEx($request, $handler, $uri, $method, $bodyContent);
                $retry = $request->getAttribute(Attributes::RETRY, 0);
                $result = null;
                for ($i = 0; $i <= $retry; ++$i)
                {
                    $receiveHeaders = [];
                    $curlResult = curl_exec($handler);
                    $this->result = $this->getResponse($request, $handler, $curlResult, $receiveHeaders);
                    $result = &$this->result;
                    $statusCode = $result->getStatusCode();
                    // 状态码为5XX或者0才需要重试
                    if (!(0 === $statusCode || (5 === (int) ($statusCode / 100))))
                    {
                        break;
                    }
                }
                if ($request->getAttribute(Attributes::FOLLOW_LOCATION, true) && ($statusCode >= 300 && $statusCode < 400) && $result && '' !== ($location = $result->getHeaderLine('location')))
                {
                    $maxRedirects = $request->getAttribute(Attributes::MAX_REDIRECTS, 10);
                    if (++$redirectCount <= $maxRedirects)
                    {
                        // 重定向清除之前下载的文件
                        if (null !== $saveFileFp)
                        {
                            ftruncate($saveFileFp, 0);
                            fseek($saveFileFp, 0);
                        }
                        $isLocation = true;
                        $uri = $this->parseRedirectLocation($location, $uri);
                        continue;
                    }
                    else
                    {
                        $result = $result->withErrno(-1)
                                        ->withError(sprintf('Maximum (%s) redirects followed', $maxRedirects));
                    }
                }
                break;
            } while (true);
            // 关闭保存至文件的句柄
            if (null !== $saveFileFp)
            {
                fclose($saveFileFp);
                $saveFileFp = null;
            }
        }
        finally
        {
            if ($poolIsEnabled && $this->handler)
            {
                // @phpstan-ignore-next-line
                $httpConnectionManager->release($this->poolKey, $this->handler);
                $this->handler = null;
            }
        }
    }

    /**
     * 构建基础 Curl Handler.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     * @param resource                           $handler
     * @param array|null                         $headers
     * @param resource|null                      $saveFileFp
     *
     * @return void
     */
    public function buildCurlHandlerBase(&$request, $handler, &$headers = null, &$saveFileFp = null)
    {
        $options = [
            // 返回内容
            \CURLOPT_RETURNTRANSFER  => true,
            // 保存cookie
            \CURLOPT_COOKIEJAR       => 'php://memory',
            // 允许复用连接
            \CURLOPT_FORBID_REUSE    => false,
        ];
        // 自动重定向
        $options[\CURLOPT_MAXREDIRS] = $request->getAttribute(Attributes::MAX_REDIRECTS, 10);

        // 自动解压缩支持
        $acceptEncoding = $request->getHeaderLine('Accept-Encoding');
        if ('' !== $acceptEncoding)
        {
            $options[\CURLOPT_ENCODING] = $acceptEncoding;
        }
        else
        {
            $options[\CURLOPT_ENCODING] = '';
        }
        curl_setopt_array($handler, $options);
        $this->parseSSL($request, $handler);
        $this->parseOptions($request, $handler, $headers, $saveFileFp);
        $this->parseProxy($request, $handler);
        $this->parseHeaders($request, $handler);
        $this->parseCookies($request, $handler);
        $this->parseNetwork($request, $handler);
    }

    /**
     * 构建扩展 Curl Handler.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     * @param resource                           $handler
     * @param UriInterface|null                  $uri
     * @param string|null                        $method
     * @param string|null                        $body
     *
     * @return void
     */
    public function buildCurlHandlerEx(&$request, $handler, $uri = null, $method = null, $body = null)
    {
        if (null === $uri)
        {
            $uri = $request->getUri();
        }
        if (null === $method)
        {
            $method = $request->getMethod();
        }
        if (null === $body)
        {
            $body = (string) $request->getBody();
        }
        switch ($request->getProtocolVersion())
        {
            case '1.0':
                $httpVersion = \CURL_HTTP_VERSION_1_0;
                break;
            case '2.0':
                $ssl = 'https' === $uri->getScheme();
                if ($ssl)
                {
                    $httpVersion = \CURL_HTTP_VERSION_2TLS;
                }
                else
                {
                    $httpVersion = \CURL_HTTP_VERSION_2;
                }
                break;
            default:
                $httpVersion = \CURL_HTTP_VERSION_1_1;
        }
        $requestOptions = [
            \CURLOPT_URL             => (string) $uri,
            \CURLOPT_HTTP_VERSION    => $httpVersion,
        ];
        // 请求方法
        if ($body && 'GET' !== $method)
        {
            $requestOptions[\CURLOPT_POSTFIELDS] = $body;
        }
        $requestOptions[\CURLOPT_CUSTOMREQUEST] = $method;
        if ('HEAD' === $method)
        {
            $requestOptions[\CURLOPT_NOBODY] = true;
        }
        curl_setopt_array($handler, $requestOptions);
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
     * 获取响应对象
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     * @param resource                           $handler
     * @param string|bool                        $body
     * @param array                              $receiveHeaders
     *
     * @return \Yurun\Util\YurunHttp\Http\Response
     */
    private function getResponse($request, $handler, $body, $receiveHeaders)
    {
        // PHP 7.0.0开始substr()的 string 字符串长度与 start 相同时将返回一个空字符串。在之前的版本中，这种情况将返回 FALSE 。
        if (false === $body)
        {
            $body = '';
        }

        // body
        $result = new Response($body, curl_getinfo($handler, \CURLINFO_HTTP_CODE));

        // headers
        $rawHeaders = implode('', $receiveHeaders);
        $headers = $this->parseHeaderOneRequest($rawHeaders);
        foreach ($headers as $name => $value)
        {
            $result = $result->withAddedHeader($name, $value);
        }

        // cookies
        $cookies = [];
        $count = preg_match_all('/([^\r\n]+)/i', implode(\PHP_EOL, $result->getHeader('set-cookie')), $matches);
        $cookieManager = $this->cookieManager;
        for ($i = 0; $i < $count; ++$i)
        {
            $cookieItem = $cookieManager->addSetCookie($matches[1][$i]);
            $cookies[$cookieItem->name] = (array) $cookieItem;
        }

        // 下载文件名
        if ($savedFileName = $request->getAttribute(Attributes::SAVE_FILE_PATH))
        {
            $result = $result->withSavedFileName($savedFileName);
        }

        return $result->withRequest($request)
                      ->withCookieOriginParams($cookies)
                      ->withError(curl_error($handler))
                      ->withErrno(curl_errno($handler));
    }

    /**
     * parseHeaderOneRequest.
     *
     * @param string $piece
     *
     * @return array
     */
    private function parseHeaderOneRequest($piece)
    {
        $tmpHeaders = [];
        $lines = explode("\r\n", $piece);
        $linesCount = \count($lines);
        //从1开始，第0行包含了协议信息和状态信息，排除该行
        for ($i = 1; $i < $linesCount; ++$i)
        {
            $line = trim($lines[$i]);
            if (empty($line) || false == strstr($line, ':'))
            {
                continue;
            }
            list($key, $value) = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (isset($tmpHeaders[$key]))
            {
                if (\is_array($tmpHeaders[$key]))
                {
                    $tmpHeaders[$key][] = $value;
                }
                else
                {
                    $tmp = $tmpHeaders[$key];
                    $tmpHeaders[$key] = [
                        $tmp,
                        $value,
                    ];
                }
            }
            else
            {
                $tmpHeaders[$key] = $value;
            }
        }

        return $tmpHeaders;
    }

    /**
     * 处理加密访问.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     * @param resource                           $handler
     *
     * @return void
     */
    private function parseSSL(&$request, $handler)
    {
        if ($request->getAttribute(Attributes::IS_VERIFY_CA, false))
        {
            curl_setopt_array($handler, [
                \CURLOPT_SSL_VERIFYPEER    => true,
                \CURLOPT_CAINFO            => $request->getAttribute(Attributes::CA_CERT),
                \CURLOPT_SSL_VERIFYHOST    => 2,
            ]);
        }
        else
        {
            curl_setopt_array($handler, [
                \CURLOPT_SSL_VERIFYPEER    => false,
                \CURLOPT_SSL_VERIFYHOST    => 0,
            ]);
        }
        $certPath = $request->getAttribute(Attributes::CERT_PATH, '');
        if ('' !== $certPath)
        {
            curl_setopt_array($handler, [
                \CURLOPT_SSLCERT         => $certPath,
                \CURLOPT_SSLCERTPASSWD   => $request->getAttribute(Attributes::CERT_PASSWORD),
                \CURLOPT_SSLCERTTYPE     => $request->getAttribute(Attributes::CERT_TYPE, 'pem'),
            ]);
        }
        $keyPath = $request->getAttribute(Attributes::KEY_PATH, '');
        if ('' !== $keyPath)
        {
            curl_setopt_array($handler, [
                \CURLOPT_SSLKEY          => $keyPath,
                \CURLOPT_SSLKEYPASSWD    => $request->getAttribute(Attributes::KEY_PASSWORD),
                \CURLOPT_SSLKEYTYPE      => $request->getAttribute(Attributes::KEY_TYPE, 'pem'),
            ]);
        }
    }

    /**
     * 处理设置项.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     * @param resource                           $handler
     * @param array                              $headers
     * @param resource|null                      $saveFileFp
     *
     * @return void
     */
    private function parseOptions(&$request, $handler, &$headers = null, &$saveFileFp = null)
    {
        $options = $request->getAttribute(Attributes::OPTIONS, []);
        if (isset($options[\CURLOPT_HEADERFUNCTION]))
        {
            $headerCallable = $options[\CURLOPT_HEADERFUNCTION];
        }
        else
        {
            $headerCallable = null;
        }
        $headers = [];
        $options[\CURLOPT_HEADERFUNCTION] = function ($handler, $header) use ($headerCallable, &$headers) {
            $headers[] = $header;
            if ($headerCallable)
            {
                $headerCallable($handler, $header);
            }

            return \strlen($header);
        };
        curl_setopt_array($handler, $options);
        // 请求结果保存为文件
        if (null !== ($saveFilePath = $request->getAttribute(Attributes::SAVE_FILE_PATH)))
        {
            $last = substr($saveFilePath, -1, 1);
            if ('/' === $last || '\\' === $last)
            {
                // 自动获取文件名
                $saveFilePath .= basename($request->getUri()->__toString());
            }
            $saveFileFp = fopen($saveFilePath, $request->getAttribute(Attributes::SAVE_FILE_MODE, 'w+'));
            curl_setopt_array($handler, [
                \CURLOPT_HEADER          => false,
                \CURLOPT_RETURNTRANSFER  => false,
                \CURLOPT_FILE            => $saveFileFp,
            ]);
        }
    }

    /**
     * 处理代理.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     * @param resource                           $handler
     *
     * @return void
     */
    private function parseProxy(&$request, $handler)
    {
        if ($request->getAttribute(Attributes::USE_PROXY, false))
        {
            $type = $request->getAttribute(Attributes::PROXY_TYPE, 'http');
            curl_setopt_array($handler, [
                \CURLOPT_PROXYAUTH    => self::$proxyAuths[$request->getAttribute(Attributes::PROXY_AUTH, 'basic')],
                \CURLOPT_PROXY        => $request->getAttribute(Attributes::PROXY_SERVER),
                \CURLOPT_PROXYPORT    => $request->getAttribute(Attributes::PROXY_PORT),
                \CURLOPT_PROXYUSERPWD => $request->getAttribute(Attributes::PROXY_USERNAME, '') . ':' . $request->getAttribute(Attributes::PROXY_PASSWORD, ''),
                \CURLOPT_PROXYTYPE    => 'socks5' === $type ? (\defined('CURLPROXY_SOCKS5_HOSTNAME') ? \CURLPROXY_SOCKS5_HOSTNAME : self::$proxyType[$type]) : self::$proxyType[$type],
            ]);
        }
    }

    /**
     * 处理headers.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     * @param resource                           $handler
     *
     * @return void
     */
    private function parseHeaders(&$request, $handler)
    {
        if (!$request->hasHeader('User-Agent'))
        {
            $request = $request->withHeader('User-Agent', $request->getAttribute(Attributes::USER_AGENT, static::$defaultUA));
        }
        if (!$request->hasHeader('Connection'))
        {
            $request = $request->withHeader('Connection', 'Keep-Alive')->withHeader('Keep-Alive', '300');
        }
        curl_setopt($handler, \CURLOPT_HTTPHEADER, $this->parseHeadersFormat($request));
    }

    /**
     * 处理成CURL可以识别的headers格式.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     *
     * @return array
     */
    private function parseHeadersFormat($request)
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $value)
        {
            $headers[] = $name . ': ' . implode(',', $value);
        }

        return $headers;
    }

    /**
     * 处理cookie.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     * @param resource                           $handler
     *
     * @return void
     */
    private function parseCookies(&$request, $handler)
    {
        $cookieManager = $this->cookieManager;
        foreach ($request->getCookieParams() as $name => $value)
        {
            $cookieManager->setCookie($name, $value);
        }
        $cookie = $cookieManager->getRequestCookieString($request->getUri());
        curl_setopt($handler, \CURLOPT_COOKIE, $cookie);
    }

    /**
     * 处理网络相关.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     * @param resource                           $handler
     *
     * @return void
     */
    private function parseNetwork(&$request, $handler)
    {
        // 用户名密码处理
        $username = $request->getAttribute(Attributes::USERNAME);
        if (null != $username)
        {
            $userPwd = $username . ':' . $request->getAttribute(Attributes::PASSWORD, '');
        }
        else
        {
            $userPwd = '';
        }
        curl_setopt_array($handler, [
            // 连接超时
            \CURLOPT_CONNECTTIMEOUT_MS       => $request->getAttribute(Attributes::CONNECT_TIMEOUT, 30000),
            // 总超时
            \CURLOPT_TIMEOUT_MS              => $request->getAttribute(Attributes::TIMEOUT, 0),
            // 下载限速
            \CURLOPT_MAX_RECV_SPEED_LARGE    => $request->getAttribute(Attributes::DOWNLOAD_SPEED),
            // 上传限速
            \CURLOPT_MAX_SEND_SPEED_LARGE    => $request->getAttribute(Attributes::UPLOAD_SPEED),
            // 连接中用到的用户名和密码
            \CURLOPT_USERPWD                 => $userPwd,
        ]);
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
        throw new \RuntimeException('Curl Handler does not support WebSocket');
    }

    /**
     * 获取原始处理器对象
     *
     * @return mixed
     */
    public function getHandler()
    {
        return $this->handler;
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
        $this->checkRequests($requests);
        $mh = curl_multi_init();
        $curlHandlers = $recvHeaders = $saveFileFps = [];
        $result = [];
        $needRedirectRequests = [];
        try
        {
            foreach ($requests as $k => $request)
            {
                $result[$k] = null;
                $curlHandler = curl_init();
                $recvHeaders[$k] = $saveFileFps[$k] = null;
                $this->buildCurlHandlerBase($request, $curlHandler, $recvHeaders[$k], $saveFileFps[$k]);
                $files = $request->getUploadedFiles();
                $body = (string) $request->getBody();
                if (!empty($files))
                {
                    $body = FormDataBuilder::build($body, $files, $boundary);
                    $request = $request->withHeader('Content-Type', MediaType::MULTIPART_FORM_DATA . '; boundary=' . $boundary);
                }
                $this->buildCurlHandlerEx($request, $curlHandler, null, null, $body);
                curl_multi_add_handle($mh, $curlHandler);
                $curlHandlers[$k] = $curlHandler;
            }
            $running = null;
            $beginTime = microtime(true);
            // 执行批处理句柄
            do
            {
                curl_multi_exec($mh, $running);
                if ($running > 0)
                {
                    if ($timeout && microtime(true) - $beginTime >= $timeout)
                    {
                        break;
                    }
                    usleep(5000); // 每次延时 5 毫秒
                }
                else
                {
                    break;
                }
            } while (true);
            foreach ($requests as $k => $request)
            {
                $handler = $curlHandlers[$k];
                $receiveHeaders = $recvHeaders[$k];
                $curlResult = curl_multi_getcontent($handler);
                // @phpstan-ignore-next-line
                $response = $this->getResponse($request, $handler, $curlResult, $receiveHeaders);
                // 重定向处理
                $statusCode = $response->getStatusCode();
                $redirectCount = $request->getAttribute(Attributes::PRIVATE_REDIRECT_COUNT);
                if ($request->getAttribute(Attributes::FOLLOW_LOCATION, true) && ($statusCode >= 300 && $statusCode < 400) && '' !== ($location = $response->getHeaderLine('location')))
                {
                    $maxRedirects = $request->getAttribute(Attributes::MAX_REDIRECTS, 10);
                    if (++$redirectCount <= $maxRedirects)
                    {
                        $request = $request->withAttribute(Attributes::PRIVATE_REDIRECT_COUNT, $redirectCount);
                        if (\in_array($statusCode, [301, 302, 303]))
                        {
                            $request = $request->withMethod('GET')->withBody(new MemoryStream());
                        }
                        $request = $request->withUri($this->parseRedirectLocation($location, $request->getUri()));
                        $needRedirectRequests[$k] = $request;
                        continue;
                    }
                    else
                    {
                        $response = $response->withErrno(-1)
                                                    ->withError(sprintf('Maximum (%s) redirects followed', $maxRedirects));
                    }
                }
                $result[$k] = $response;
            }
        }
        finally
        {
            foreach ($saveFileFps as $fp)
            {
                // @phpstan-ignore-next-line
                if ($fp)
                {
                    fclose($fp);
                }
            }
            foreach ($curlHandlers as $curlHandler)
            {
                curl_multi_remove_handle($mh, $curlHandler);
                curl_close($curlHandler);
            }
            curl_multi_close($mh);
        }
        if ($needRedirectRequests)
        {
            foreach ($this->coBatch($needRedirectRequests, $timeout) as $k => $response)
            {
                $result[$k] = $response;
            }
        }

        return $result;
    }
}
