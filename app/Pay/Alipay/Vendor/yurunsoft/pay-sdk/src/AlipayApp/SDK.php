<?php

namespace Yurun\PaySDK\AlipayApp;

use Yurun\PaySDK\Base;
use Yurun\PaySDK\Lib\CertUtil;
use Yurun\PaySDK\Lib\Encrypt\AES;
use Yurun\PaySDK\Lib\ObjectToArray;

/**
 * 支付宝开放平台SDK类.
 */
class SDK extends Base
{
    /**
     * 公共参数.
     *
     * @var \Yurun\PaySDK\AlipayApp\Params\PublicParams
     */
    public $publicParams;

    /**
     * 支付宝公钥证书SN.
     *
     * @var string
     */
    public $alipayCertSn;

    /**
     * 应用公钥证书SN.
     *
     * @var string
     */
    public $appCertSn;

    /**
     * 支付宝根证书SN.
     *
     * @var string
     */
    public $alipayRootCertSn;

    /**
     * __construct.
     *
     * @param \Yurun\PaySDK\AlipayApp\Params\PublicParams $publicParams
     */
    public function __construct($publicParams)
    {
        parent::__construct($publicParams);
        if ($publicParams->usePublicKeyCert)
        {
            $this->alipayCertSn = CertUtil::getCertSN($publicParams->alipayCertPath);
            $this->appCertSn = CertUtil::getCertSN($publicParams->merchantCertPath);
            $this->alipayRootCertSn = CertUtil::getRootCertSN($publicParams->alipayRootCertPath);
        }
    }

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
        unset($data['apiDomain'], $data['appID'], $data['businessParams'], $data['appPrivateKey'], $data['appPrivateKeyFile'], $data['appPublicKey'], $data['appPublicKeyFile'], $data['_syncResponseName'], $data['_method'], $data['_isSyncVerify'], $data['aesKey'], $data['isUseAES'], $data['alipayCertPath'], $data['alipayRootCertPath'], $data['merchantCertPath'], $data['usePublicKeyCert'], $data['_contentType']);
        $data['app_id'] = $this->publicParams->appID;
        $data['biz_content'] = $params->businessParams->toString();
        if ($this->publicParams->isUseAES)
        {
            $data['biz_content'] = AES::encrypt($data['biz_content'], base64_decode($this->publicParams->aesKey));
        }
        // 公钥证书参数
        if ($this->publicParams->usePublicKeyCert)
        {
            $data['app_cert_sn'] = $this->appCertSn;
            $data['alipay_root_cert_sn'] = $this->alipayRootCertSn;
        }
        $data['timestamp'] = date('Y-m-d H:i:s');
        $data['sign'] = $this->sign($data);
        $requestData = $data;
        $url = $this->publicParams->apiDomain;
    }

    /**
     * 签名.
     *
     * @param $data
     *
     * @return string
     */
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
            case 'RSA':
                $result = \Yurun\PaySDK\Lib\Encrypt\RSA::$method($content, $key);
                break;
            case 'RSA2':
                $result = \Yurun\PaySDK\Lib\Encrypt\RSA2::$method($content, $key);
                break;
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
        $signType = $data['sign_type'];
        unset($data['sign_type']);
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
        switch ($signType)
        {
            case 'RSA':
                return \Yurun\PaySDK\Lib\Encrypt\RSA::$method($content, $key, base64_decode($data['sign']));
            case 'RSA2':
                return \Yurun\PaySDK\Lib\Encrypt\RSA2::$method($content, $key, base64_decode($data['sign']));
            default:
                throw new \Exception('未知的加密方式：' . $signType);
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
        if (!isset($data['sign']))
        {
            return true;
        }
        // 公钥证书验证
        if ($this->publicParams->usePublicKeyCert && $this->alipayCertSn !== (isset($data['alipay_cert_sn']) ? $data['alipay_cert_sn'] : null))
        {
            return false;
        }
        $content = json_encode($data[$params->_syncResponseName], \JSON_UNESCAPED_UNICODE);
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
        switch ($this->publicParams->sign_type)
        {
            case 'RSA':
                return \Yurun\PaySDK\Lib\Encrypt\RSA::$method($content, $key, base64_decode($data['sign']));
            case 'RSA2':
                return \Yurun\PaySDK\Lib\Encrypt\RSA2::$method($content, $key, base64_decode($data['sign']));
            default:
                throw new \Exception('未知的加密方式：' . $this->publicParams->sign_type);
        }
    }

    public function parseSignData($data)
    {
        unset($data['sign']);
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
    public function execute($params, $format = 'JSON')
    {
        $result = parent::execute($params, $format);
        if ($this->publicParams->isUseAES && isset($result[$params->_syncResponseName]))
        {
            $result[$params->_syncResponseName] = json_decode(AES::decrypt($result[$params->_syncResponseName], base64_decode($this->publicParams->aesKey)), true);
        }

        return $result;
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
        if (!\is_array($result))
        {
            return false;
        }
        $result = reset($result);

        return isset($result['code']) && 10000 == $result['code'] && !isset($result['sub_code']);
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
        if (!\is_array($result))
        {
            return '';
        }
        $result = reset($result);
        if (isset($result['sub_code']))
        {
            return $result['sub_msg'];
        }
        if (isset($result['code']) && 10000 != $result['code'])
        {
            return $result['msg'];
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
        if (!\is_array($result))
        {
            return '';
        }
        $result = reset($result);
        if (isset($result['sub_code']))
        {
            return $result['sub_code'];
        }
        if (isset($result['code']) && 10000 != $result['code'])
        {
            return $result['code'];
        }

        return '';
    }
}
