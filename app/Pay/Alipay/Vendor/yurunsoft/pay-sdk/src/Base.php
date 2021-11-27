<?php

namespace Yurun\PaySDK;

use Yurun\PaySDK\Lib\XML;
use Yurun\Util\HttpRequest;

/**
 * SDK类基类.
 */
abstract class Base
{
    /**
     * HttpRequest.
     *
     * @var \Yurun\Util\HttpRequest
     */
    public $http;

    /**
     * 接口请求的返回结果.
     *
     * @var \Yurun\Util\YurunHttp\Http\Response
     */
    public $response;

    /**
     * 请求数据.
     *
     * @var array
     */
    public $requestData;

    /**
     * 公共参数.
     *
     * @var mixed
     */
    public $publicParams;

    /**
     * 最后请求的url地址
     *
     * @var string
     */
    public $url;

    /**
     * 最后请求的结果.
     *
     * @var mixed
     */
    public $result;

    /**
     * swoole 请求类，或支持 PSR-7 标准的对象
     *
     * @var \Swoole\Http\Request|\Psr\Http\Message\ServerRequestInterface
     */
    public $swooleRequest;

    /**
     * swoole 响应类，或支持 PSR-7 标准的对象
     *
     * @var \Swoole\Http\Response|\Psr\Http\Message\ResponseInterface
     */
    public $swooleResponse;

    public function __construct($publicParams)
    {
        $this->publicParams = $publicParams;
        $this->http = new HttpRequest();
    }

    /**
     * 调用执行接口.
     *
     * @param RequestBase $params
     * @param string      $method
     *
     * @return mixed
     */
    public function execute($params, $format = 'JSON')
    {
        if ($params->__onExecute($this, $format))
        {
            return $this->result;
        }
        $this->prepareExecute($params, $url, $data);
        $this->url = $url;
        $this->response = $this->http->send($url, $this->requestData, $params->_method, $params->_contentType);
        if (!$this->response->success)
        {
            throw new \RuntimeException(sprintf('Request error: [%s] %s', $this->response->errno(), $this->response->error()));
        }
        switch ($format)
        {
            case 'JSON':
                $this->result = $this->response->json(true);
                break;
            case 'XML':
                $this->result = XML::fromString($this->response->body());
                break;
            default:
                $this->result = $this->response->body();
        }
        if (!$this->checkResult($this->result))
        {
            throw new \Exception(sprintf('Error: [%s] %s', $this->getErrorCode($this->result), $this->getError($this->result)));
        }
        if ($params->_isSyncVerify && !$this->verifySync($params, $this->result, $this->response))
        {
            throw new \Exception('同步返回数据验证失败');
        }
        else
        {
            return $this->result;
        }
    }

    /**
     * 调用执行接口，将结果保存至文件.
     *
     * @param mixed  $params
     * @param string $saveFilename
     *
     * @return void
     */
    public function executeDownload($params, $saveFilename)
    {
        $this->prepareExecute($params, $url, $data);
        $this->url = $url;
        $this->http->saveFile($saveFilename)->send($url, $this->requestData, $params->_method);
    }

    /**
     * 签名.
     *
     * @param array $data
     *
     * @return string
     */
    abstract public function sign($data);

    /**
     * 处理执行接口的数据.
     *
     * @param $params
     * @param &$data 数据数组
     * @param &$requestData 请求用的数据，格式化后的
     * @param &$url 请求地址
     *
     * @return array
     */
    abstract public function __parseExecuteData($params, &$data, &$requestData, &$url);

    /**
     * 验证回调通知是否合法.
     *
     * @param $data
     *
     * @return bool
     */
    abstract public function verifyCallback($data);

    /**
     * 验证同步返回内容.
     *
     * @param mixed                                    $params
     * @param array                                    $data
     * @param \Yurun\Util\YurunHttp\Http\Response|null $response
     *
     * @return bool
     */
    abstract public function verifySync($params, $data, $response = null);

    /**
     * 检查是否执行成功
     *
     * @param array $result
     *
     * @return bool
     */
    abstract protected function __checkResult($result);

    /**
     * 获取错误信息.
     *
     * @param array $result
     *
     * @return string
     */
    abstract protected function __getError($result);

    /**
     * 获取错误代码
     *
     * @param array $result
     *
     * @return string
     */
    abstract protected function __getErrorCode($result);

    /**
     * 检查是否执行成功
     *
     * @param array $result
     *
     * @return bool
     */
    public function checkResult($result = null)
    {
        return $this->__checkResult(null === $result ? $this->result : $result);
    }

    /**
     * 获取错误信息.
     *
     * @param array $result
     *
     * @return string
     */
    public function getError($result = null)
    {
        return $this->__getError(null === $result ? $this->result : $result);
    }

    /**
     * 获取错误代码
     *
     * @param array $result
     *
     * @return string
     */
    public function getErrorCode($result = null)
    {
        return $this->__getErrorCode(null === $result ? $this->result : $result);
    }

    /**
     * 使用跳转的方式处理.
     *
     * @param array $params
     *
     * @return void
     */
    public function redirectExecute($params)
    {
        $this->__parseExecuteData($params, $data, $requestData, $url);
        if (false === strpos($url, '?'))
        {
            $url .= '?';
        }
        else
        {
            $url .= '&';
        }
        $this->requestData = $data;
        $url .= http_build_query($data, '', '&');
        if (null === $this->swooleResponse)
        {
            header('HTTP/1.1 302 Temporarily Moved');
            header('Status: 302 Temporarily Moved');
            header('Location: ' . $url);
            exit;
        }
        elseif ($this->swooleResponse instanceof \Swoole\Http\Response)
        {
            $this->swooleResponse->redirect($url, 302);
        }
        elseif ($this->swooleResponse instanceof \Psr\Http\Message\ResponseInterface)
        {
            $this->swooleResponse = $this->swooleResponse->withStatus(302)->withHeader('Location', $url);
        }
    }

    /**
     * 准备处理数据.
     *
     * @param $params
     * @param string $url
     * @param array  $data
     *
     * @return void
     */
    public function prepareExecute($params, &$url = null, &$data = null)
    {
        $this->__parseExecuteData($params, $data, $requestData, $url);
        $this->requestData = $requestData;
        if ('GET' === $params->_method)
        {
            if (false === strpos($url, '?'))
            {
                $url .= '?';
            }
            else
            {
                $url .= '&';
            }
            $url .= http_build_query($data, '', '&');
        }
    }

    /**
     * 处理异步通知.
     *
     * @param \Yurun\PaySDK\NotifyBase $notifyHandler
     *
     * @return void
     */
    public function notify($notifyHandler)
    {
        $notifyHandler->sdk = $this;
        $notifyHandler->exec();
    }
}
