<?php

namespace Yurun\PaySDK\Weixin;

use Yurun\PaySDK\Base;
use Yurun\PaySDK\Lib\Encrypt\SHA256withRSA\Signer;
use Yurun\PaySDK\Lib\ObjectToArray;
use Yurun\PaySDK\Lib\Util;
use Yurun\PaySDK\WeixinRequestBase;

/**
 * 微信支付SDK类 V3.
 *
 * @see https://wechatpay-api.gitbook.io/wechatpay-api-v3/
 */
class SDKV3 extends Base
{
    /**
     * 公共参数.
     *
     * @var \Yurun\PaySDK\Weixin\Params\PublicParams
     */
    public $publicParams;

    /**
     * 最后一次使用的 Authorization.
     *
     * @var string
     */
    public $authorization;

    /**
     * 最后一次使用的签名.
     *
     * @var string
     */
    public $sign;

    /**
     * 处理执行接口的数据.
     *
     * @param WeixinRequestBase $params
     * @param &$data 数据数组
     * @param &$requestData 请求用的数据，格式化后的
     * @param &$url 请求地址
     *
     * @return array
     */
    public function __parseExecuteData($params, &$data, &$requestData, &$url)
    {
        $data = array_merge(ObjectToArray::parse($this->publicParams), ObjectToArray::parse($params));
        // 删除不必要的字段
        unset($data['apiDomain'], $data['appID'], $data['businessParams'], $data['_apiMethod'], $data['key'], $data['_method'], $data['_isSyncVerify'], $data['certPath'], $data['keyPath'], $data['apiCertPath'], $data['certSerialNumber'], $data['needSignType'], $data['allowReport'], $data['reportLevel'], $data['needNonceStr'], $data['signType'], $data['needAppID'], $data['rsaPublicCertFile'], $data['rsaPublicCertContent'], $data['needMchID'], $data['_contentType'], $data['keyV3']);
        // 企业付款接口特殊处理
        if ($params->needAppID)
        {
            if (isset($params->mch_appid))
            {
                if ('' === $params->mch_appid)
                {
                    $data['mch_appid'] = $this->publicParams->appID;
                }
            }
            else
            {
                $data['appid'] = $this->publicParams->appID;
            }
        }
        if (!$params->needMchID)
        {
            unset($data['mch_id']);
        }
        if (isset($params->mchid) && '' === $params->mchid)
        {
            $data['mchid'] = $this->publicParams->mch_id;
            unset($data['mch_id']);
        }
        if (isset($params->partnerid) && '' === $params->partnerid)
        {
            $data['partnerid'] = $this->publicParams->mch_id;
            unset($data['mch_id']);
        }
        // 部分接口不需要sign_type字段
        if (!$params->needSignType)
        {
            unset($data['sign_type']);
        }
        foreach ($data as $key => $value)
        {
            if (\is_object($value) && method_exists($value, 'toString'))
            {
                $data[$key] = $value->toString();
            }
        }
        $this->authorization = $this->generateAuthorization($data, $params);
        if (false === strpos($params->_apiMethod, '://'))
        {
            $url = $this->publicParams->apiDomain . $params->_apiMethod;
        }
        else
        {
            $url = $params->_apiMethod;
        }
    }

    /**
     * 生成 Authorization.
     *
     * @param array             $data
     * @param WeixinRequestBase $params
     *
     * @return string
     */
    public function generateAuthorization($data, $params)
    {
        $timestamp = Util::getBeijingTime();
        $nonceStr = md5(mt_rand());
        $this->sign = $this->sign([
            'data'      => $data,
            'params'    => $params,
            'timestamp' => $timestamp,
            'nonce_str' => $nonceStr,
        ]);
        $this->http->accept('application/json')
                   ->header('Authorization', sprintf('WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%s",serial_no="%s"', $this->publicParams->mch_id, $nonceStr, $this->sign, $timestamp, $this->publicParams->certSerialNumber));
    }

    /**
     * 签名.
     *
     * @param array $data
     *
     * @return string
     */
    public function sign($data)
    {
        $content = $this->parseSignData($data);

        return Signer::sign($content, $this->publicParams->certSerialNumber, openssl_get_privatekey(file_get_contents($this->publicParams->keyPath)))->getSign();
    }

    /**
     * 验证回调通知是否合法.
     *
     * @param mixed $data
     *
     * @return bool
     */
    public function verifyCallback($data)
    {
        return false;
    }

    /**
     * 验证同步返回内容.
     *
     * @param mixed                                    $params
     * @param array                                    $data
     * @param \Yurun\Util\YurunHttp\Http\Response|null $response
     *
     * @return bool
     */
    public function verifySync($params, $data, $response = null)
    {
        $timestamp = $response->getHeaderLine('Wechatpay-Timestamp');
        // 5 分钟误差验证
        if (abs(Util::getBeijingTime() - $timestamp) > 300)
        {
            throw new \RuntimeException('微信时间戳与本地时间相差过大');
        }
        $content = $timestamp . "\n"
                . $response->getHeaderLine('Wechatpay-Nonce') . "\n"
                . $response->getBody() . "\n";
        $sign = $response->getHeaderLine('Wechatpay-Signature');

        return Signer::verify($content, $sign, openssl_get_publickey(file_get_contents($this->publicParams->certPath)));
    }

    public function parseSignData($data)
    {
        /** @var WeixinRequestBase $params */
        $params = $data['params'];

        return $params->_method . "\n"
                . '/' . $params->_apiMethod . "\n"
                . $data['timestamp'] . "\n"
                . $data['nonce_str'] . "\n"
                . (\in_array($params->_method, ['POST', 'PUT']) ? json_encode($data['data']) : '') . "\n";
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
        if (null !== $this->publicParams->certPath)
        {
            $this->http->sslCert($this->publicParams->certPath);
        }
        if (null !== $this->publicParams->keyPath)
        {
            $this->http->sslKey($this->publicParams->keyPath);
        }
        parent::execute($params, $format);

        return $this->result;
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
        return !isset($result['code']);
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
        return isset($result['message']) ? $result['message'] : '';
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
        return isset($result['code']) ? $result['code'] : '';
    }
}
