<?php

namespace Yurun\PaySDK\AlipayCrossBorder;

use Yurun\PaySDK\Base;
use Yurun\PaySDK\Lib\ObjectToArray;

/**
 * 支付宝境外支付SDK类.
 */
class SDK extends Base
{
    /**
     * 公共参数.
     *
     * @var \Yurun\PaySDK\AlipayCrossBorder\Params\PublicParams
     */
    public $publicParams;

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
    public function __parseExecuteData($params, &$data, &$requestData, &$url)
    {
        $data = array_merge(ObjectToArray::parse($this->publicParams), ObjectToArray::parse($params));
        unset($data['apiDomain'], $data['appID'], $data['appPrivateKey'], $data['appPrivateKeyFile'], $data['md5Key'], $data['appPublicKey'], $data['appPublicKeyFile'], $data['_syncResponseName'], $data['_method'], $data['_isSyncVerify'], $data['_contentType']);
        $data['partner'] = $this->publicParams->appID;
        foreach ($data as $key => $value)
        {
            if ('' == $value)
            {
                unset($data[$key]);
            }
        }
        $data['sign'] = $this->sign($data);
        $requestData = $data;
        $url = $this->publicParams->apiDomain;
    }

    public function sign($data)
    {
        $content = $this->parseSignData($data);
        if (empty($this->publicParams->appPrivateKeyFile))
        {
            $key = $this->publicParams->appPrivateKey;
            $method = 'signPrivate';
        }
        else
        {
            $key = $this->publicParams->appPrivateKeyFile;
            $method = 'signPrivateFromFile';
        }
        switch ($this->publicParams->sign_type)
        {
            case 'DSA':
                $result = \Yurun\PaySDK\Lib\Encrypt\DSA::$method($content, $key);
                break;
            case 'RSA':
                $result = \Yurun\PaySDK\Lib\Encrypt\RSA::$method($content, $key);
                break;
            case 'RSA2':
                $result = \Yurun\PaySDK\Lib\Encrypt\RSA2::$method($content, $key);
                break;
            case 'MD5':
                return md5($content . $this->publicParams->md5Key);
            default:
                throw new \Exception('未知的加密方式：' . $this->publicParams->sign_type);
        }

        return base64_encode($result);
    }

    /**
     * 验证回调通知是否合法.
     *
     * @param $data
     *
     * @return bool
     */
    public function verifyCallback($data)
    {
        if (!isset($data['sign'], $data['sign_type']))
        {
            return false;
        }
        $content = $this->parseSignData($data);
        if (empty($this->publicParams->appPublicKeyFile))
        {
            $key = $this->publicParams->appPublicKey;
            $method = 'verifyPublic';
        }
        else
        {
            $key = $this->publicParams->appPublicKeyFile;
            $method = 'verifyPublicFromFile';
        }
        switch ($data['sign_type'])
        {
            case 'DSA':
                return \Yurun\PaySDK\Lib\Encrypt\DSA::$method($content, $key, base64_decode($data['sign']));
            case 'RSA':
                return \Yurun\PaySDK\Lib\Encrypt\RSA::$method($content, $key, base64_decode($data['sign']));
            case 'MD5':
                return $data['sign'] === md5($content . $this->publicParams->md5Key);
            default:
                throw new \Exception('未知的加密方式：' . $data['sign_type']);
        }
    }

    /**
     * 验证同步返回内容.
     *
     * @param AlipayRequestBase                        $params
     * @param array                                    $data
     * @param \Yurun\Util\YurunHttp\Http\Response|null $response
     *
     * @return bool
     */
    public function verifySync($params, $data, $response = null)
    {
        if (!isset($data['sign'], $data['sign_type'], $data['response']))
        {
            return true;
        }
        $response = (array) $data['response'];
        $content = $this->parseSignData((array) reset($response));
        if (empty($this->publicParams->appPublicKeyFile))
        {
            $key = $this->publicParams->appPublicKey;
            $method = 'verifyPublic';
        }
        else
        {
            $key = $this->publicParams->appPublicKeyFile;
            $method = 'verifyPublicFromFile';
        }
        switch ($data['sign_type'])
        {
            case 'DSA':
                return \Yurun\PaySDK\Lib\Encrypt\DSA::$method($content, $key, base64_decode($data['sign']));
            case 'RSA':
                return \Yurun\PaySDK\Lib\Encrypt\RSA::$method($content, $key, base64_decode($data['sign']));
            case 'MD5':
                return $data['sign'] === md5($content . $this->publicParams->md5Key);
            default:
                throw new \Exception('未知的加密方式：' . $data['sign_type']);
        }
    }

    public function parseSignData($data)
    {
        unset($data['sign_type'], $data['sign']);
        ksort($data);
        $content = '';
        foreach ($data as $k => $v)
        {
            if ('' !== $v && null !== $v && !\is_array($v))
            {
                $content .= $k . '=' . $v . '&';
            }
        }

        return trim($content, '&');
    }

    /**
     * 调用执行接口.
     *
     * @param mixed  $params
     * @param string $method
     *
     * @return mixed
     */
    public function execute($params, $format = 'XML')
    {
        return parent::execute($params, $format);
    }

    /**
     * 检查是否执行成功
     *
     * @param array $result
     *
     * @return bool
     */
    protected function __checkResult($result)
    {
        if (isset($result['is_success']) && 'T' === $result['is_success'])
        {
            if (isset($result['response']))
            {
                $response = (array) $result['response'];
                $item = reset($response);

                return !isset($item->result_code) || 'SUCCESS' === (string) $item->result_code;
            }
            else
            {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取错误信息.
     *
     * @param array $result
     *
     * @return string
     */
    protected function __getError($result)
    {
        if (isset($result['is_success']))
        {
            if ('T' === $result['is_success'])
            {
                if (isset($result['response']))
                {
                    $response = (array) $result['response'];
                    $item = reset($response);
                    if (isset($item->result_code) && 'SUCCESS' !== (string) $item->result_code)
                    {
                        if (isset($item->error))
                        {
                            return (string) $item->error;
                        }
                        else
                        {
                            return (string) $item->detail_error_des;
                        }
                    }
                }
            }
            else
            {
                return $result['error'];
            }
        }

        return '';
    }

    /**
     * 获取错误代码
     *
     * @param array $result
     *
     * @return string
     */
    protected function __getErrorCode($result)
    {
        if (isset($result['is_success']))
        {
            if ('T' === $result['is_success'])
            {
                if (isset($result['response']))
                {
                    $response = (array) $result['response'];
                    $item = reset($response);
                    if (isset($item->result_code) && 'SUCCESS' !== (string) $item->result_code)
                    {
                        if (isset($item->result_code))
                        {
                            return (string) $item->result_code;
                        }
                        else
                        {
                            return (string) $item->detail_error_code;
                        }
                    }
                }
            }
            else
            {
                return $result['error'];
            }
        }

        return '';
    }
}
