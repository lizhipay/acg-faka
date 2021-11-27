<?php

namespace Yurun\PaySDK\Weixin\Notify;

use Yurun\PaySDK\Lib\Encrypt\AES256GCM;
use Yurun\PaySDK\Lib\Encrypt\SHA256withRSA\Signer;
use Yurun\PaySDK\Lib\Util;
use Yurun\PaySDK\NotifyBase;
use Yurun\PaySDK\Weixin\Reply\BaseV3 as ReplyBase;
use Yurun\Util\YurunHttp\Stream\MemoryStream;

/**
 * 微信支付V3-通知处理基类.
 */
abstract class BaseV3 extends NotifyBase
{
    public function __construct()
    {
        parent::__construct();
        $this->replyData = new ReplyBase();
    }

    /**
     * 返回数据.
     *
     * @param bool   $success
     * @param string $message
     *
     * @return void
     */
    public function reply($success, $message = '')
    {
        $this->replyData->code = $success ? 'SUCCESS' : 'FAIL';
        $this->replyData->message = $message;
        if (null === $this->swooleResponse)
        {
            echo $this->replyData;
        }
        elseif ($this->swooleResponse instanceof \Swoole\Http\Response)
        {
            $this->swooleResponse->end($this->replyData->toString());
        }
        elseif ($this->swooleResponse instanceof \Psr\Http\Message\ResponseInterface)
        {
            $this->swooleResponse = $this->swooleResponse->withBody(new MemoryStream($this->replyData->toString()));
        }
    }

    /**
     * 获取通知数据.
     *
     * @return array|mixed
     */
    public function getNotifyData()
    {
        if ($this->swooleRequest instanceof \Swoole\Http\Request)
        {
            return json_decode($this->swooleRequest->rawContent(), true);
        }
        if ($this->swooleRequest instanceof \Psr\Http\Message\ServerRequestInterface)
        {
            return json_decode((string) $this->swooleRequest->getBody(), true);
        }

        return json_decode(file_get_contents('php://input'), true);
    }

    /**
     * 对通知进行验证，是否是正确的通知.
     *
     * @return bool
     */
    public function notifyVerify()
    {
        if ($this->swooleRequest instanceof \Swoole\Http\Request)
        {
            $timestamp = $this->swooleRequest->header['wechatpay-timestamp'];
            $nonce = $this->swooleRequest->header['wechatpay-nonce'];
            $sign = $this->swooleRequest->header['wechatpay-signature'];
            $body = $this->swooleRequest->rawContent();
        }
        elseif ($this->swooleRequest instanceof \Psr\Http\Message\ServerRequestInterface)
        {
            $timestamp = $this->swooleRequest->getHeaderLine('Wechatpay-Timestamp');
            $nonce = $this->swooleRequest->getHeaderLine('Wechatpay-Nonce');
            $sign = $this->swooleRequest->getHeaderLine('Wechatpay-Signature');
            $body = (string) $this->swooleRequest->getBody();
        }
        else
        {
            $timestamp = isset($_SERVER['HTTP_WECHATPAY_TIMESTAMP']) ? $_SERVER['HTTP_WECHATPAY_TIMESTAMP'] : '';
            $nonce = isset($_SERVER['HTTP_WECHATPAY_NONCE']) ? $_SERVER['HTTP_WECHATPAY_NONCE'] : '';
            $sign = isset($_SERVER['HTTP_WECHATPAY_SIGNATURE']) ? $_SERVER['HTTP_WECHATPAY_SIGNATURE'] : '';
            $body = file_get_contents('php://input');
        }

        // 5 分钟误差验证
        if (abs(Util::getBeijingTime() - $timestamp) > 300)
        {
            throw new \RuntimeException('微信时间戳与本地时间相差过大');
        }

        $content = $timestamp . "\n"
                . $nonce . "\n"
                . $body . "\n";

        $result = Signer::verify($content, $sign, openssl_get_publickey(file_get_contents($this->sdk->publicParams->certPath)));
        if ($result)
        {
            // 数据解密
            $resource = $this->data['resource'];
            $content = AES256GCM::decryptToString($this->sdk->publicParams->keyV3, $resource['associated_data'], $resource['nonce'], $resource['ciphertext']);
            $this->data['parsedResource'] = json_decode($content, true);
        }

        return $result;
    }
}
