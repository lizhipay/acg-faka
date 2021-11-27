<?php

namespace Yurun\Util\YurunHttp\Http;

use Yurun\Util\YurunHttp\Http\Psr7\Consts\StatusCode;
use Yurun\Util\YurunHttp\Http\Psr7\Response as Psr7Response;

class Response extends Psr7Response
{
    /**
     * 是否请求成功
     *
     * @var bool
     */
    public $success;

    /**
     * cookie数据.
     *
     * @var array
     */
    protected $cookies;

    /**
     * cookie原始数据，包含expires、path、domain等.
     *
     * @var array
     */
    protected $cookiesOrigin;

    /**
     * 请求总耗时，单位：秒.
     *
     * @var float
     */
    protected $totalTime;

    /**
     * 错误信息.
     *
     * @var string
     */
    protected $error;

    /**
     * 错误码
     *
     * @var int
     */
    protected $errno;

    /**
     * Http2 streamId.
     *
     * @var int
     */
    protected $streamId;

    /**
     * Request.
     *
     * @var \Yurun\Util\YurunHttp\Http\Request
     */
    protected $request;

    /**
     * 保存到的文件名.
     *
     * @var string|null
     */
    protected $savedFileName;

    /**
     * Retrieve cookies.
     *
     * Retrieves cookies sent by the client to the server.
     *
     * The data MUST be compatible with the structure of the $_COOKIE
     * superglobal.
     *
     * @return array
     */
    public function getCookieParams()
    {
        return $this->cookies;
    }

    /**
     * 获取cookie值
     *
     * @param string $name
     * @param mixed  $default
     *
     * @return mixed
     */
    public function getCookie($name, $default = null)
    {
        return isset($this->cookies[$name]) ? $this->cookies[$name] : $default;
    }

    /**
     * 设置cookie原始参数，包含expires、path、domain等.
     *
     * @param array $cookiesOrigin
     *
     * @return static
     */
    public function withCookieOriginParams(array $cookiesOrigin)
    {
        $self = clone $this;
        $self->cookiesOrigin = $cookiesOrigin;
        $self->cookies = [];
        foreach ($cookiesOrigin as $name => $value)
        {
            $self->cookies[$name] = $value['value'];
        }

        return $self;
    }

    /**
     * 获取所有cookie原始参数，包含expires、path、domain等.
     *
     * @return array
     */
    public function getCookieOriginParams()
    {
        return $this->cookiesOrigin;
    }

    /**
     * 获取cookie原始参数值，包含expires、path、domain等.
     *
     * @param string $name
     * @param mixed  $default
     *
     * @return string
     */
    public function getCookieOrigin($name, $default = null)
    {
        return isset($this->cookiesOrigin[$name]) ? $this->cookiesOrigin[$name] : $default;
    }

    /**
     * @param string|\Psr\Http\Message\StreamInterface $body
     * @param int                                      $statusCode
     * @param string|null                              $reasonPhrase
     */
    public function __construct($body = '', $statusCode = StatusCode::OK, $reasonPhrase = null)
    {
        parent::__construct($body, $statusCode, $reasonPhrase);
        $this->success = $statusCode >= 100;
    }

    /**
     * 获取返回的主体内容.
     *
     * @param string $fromEncoding 请求返回数据的编码，如果不为空则进行编码转换
     * @param string $toEncoding   要转换到的编码，默认为UTF-8
     *
     * @return string
     */
    public function body($fromEncoding = null, $toEncoding = 'UTF-8')
    {
        if (null === $fromEncoding)
        {
            return (string) $this->getBody();
        }
        else
        {
            return mb_convert_encoding((string) $this->getBody(), $toEncoding, $fromEncoding);
        }
    }

    /**
     * 获取xml格式内容.
     *
     * @param bool   $assoc        为true时返回数组，为false时返回对象
     * @param string $fromEncoding 请求返回数据的编码，如果不为空则进行编码转换
     * @param string $toEncoding   要转换到的编码，默认为UTF-8
     *
     * @return mixed
     */
    public function xml($assoc = false, $fromEncoding = null, $toEncoding = 'UTF-8')
    {
        $xml = simplexml_load_string($this->body($fromEncoding, $toEncoding), 'SimpleXMLElement', \LIBXML_NOCDATA | \LIBXML_COMPACT);
        if ($assoc)
        {
            $xml = (array) $xml;
        }

        return $xml;
    }

    /**
     * 获取json格式内容.
     *
     * @param bool   $assoc        为true时返回数组，为false时返回对象
     * @param string $fromEncoding 请求返回数据的编码，如果不为空则进行编码转换
     * @param string $toEncoding   要转换到的编码，默认为UTF-8
     *
     * @return mixed
     */
    public function json($assoc = false, $fromEncoding = null, $toEncoding = 'UTF-8')
    {
        return json_decode($this->body($fromEncoding, $toEncoding), $assoc);
    }

    /**
     * 获取jsonp格式内容.
     *
     * @param bool   $assoc        为true时返回数组，为false时返回对象
     * @param string $fromEncoding 请求返回数据的编码，如果不为空则进行编码转换
     * @param string $toEncoding   要转换到的编码，默认为UTF-8
     *
     * @return mixed
     */
    public function jsonp($assoc = false, $fromEncoding = null, $toEncoding = 'UTF-8')
    {
        $jsonp = trim($this->body($fromEncoding, $toEncoding));
        if (isset($jsonp[0]) && '[' !== $jsonp[0] && '{' !== $jsonp[0])
        {
            $begin = strpos($jsonp, '(');
            if (false !== $begin)
            {
                $end = strrpos($jsonp, ')');
                if (false !== $end)
                {
                    $jsonp = substr($jsonp, $begin + 1, $end - $begin - 1);
                }
            }
        }

        return json_decode($jsonp, $assoc);
    }

    /**
     * 获取http状态码
     *
     * @return int
     */
    public function httpCode()
    {
        return $this->getStatusCode();
    }

    /**
     * 获取请求总耗时，单位：秒.
     *
     * @return float
     */
    public function totalTime()
    {
        return $this->totalTime;
    }

    /**
     * 获取请求总耗时，单位：秒.
     *
     * @return float
     */
    public function getTotalTime()
    {
        return $this->totalTime;
    }

    /**
     * 设置请求总耗时.
     *
     * @param float $totalTime
     *
     * @return static
     */
    public function withTotalTime($totalTime)
    {
        $self = clone $this;
        $self->totalTime = $totalTime;

        return $self;
    }

    /**
     * 返回错误信息.
     *
     * @return string
     */
    public function error()
    {
        return $this->error;
    }

    /**
     * 获取错误信息.
     *
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * 设置错误信息.
     *
     * @param string $error
     *
     * @return static
     */
    public function withError($error)
    {
        $self = clone $this;
        $self->error = $error;

        return $self;
    }

    /**
     * 返回错误代码
     *
     * @return int
     */
    public function errno()
    {
        return $this->errno;
    }

    /**
     * 获取错误代码
     *
     * @return int
     */
    public function getErrno()
    {
        return $this->errno;
    }

    /**
     * 设置错误代码
     *
     * @param int $errno
     *
     * @return static
     */
    public function withErrno($errno)
    {
        $self = clone $this;
        $self->errno = $errno;

        return $self;
    }

    /**
     * 设置 Http2 streamId.
     *
     * @param int $streamId
     *
     * @return static
     */
    public function withStreamId($streamId)
    {
        $self = clone $this;
        $self->streamId = $streamId;

        return $self;
    }

    /**
     * Get http2 streamId.
     *
     * @return int
     */
    public function getStreamId()
    {
        return $this->streamId;
    }

    /**
     * 设置请求体.
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     *
     * @return static
     */
    public function withRequest($request)
    {
        $self = clone $this;
        $self->request = $request;

        return $self;
    }

    /**
     * Get request.
     *
     * @return \Yurun\Util\YurunHttp\Http\Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * 设置保存到的文件名.
     *
     * @param string|null $savedFileName
     *
     * @return static
     */
    public function withSavedFileName($savedFileName)
    {
        $self = clone $this;
        $self->savedFileName = $savedFileName;

        return $self;
    }

    /**
     * 获取保存到的文件名.
     *
     * @return string|null
     */
    public function getSavedFileName()
    {
        return $this->savedFileName;
    }
}
