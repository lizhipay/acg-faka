<?php
declare(strict_types=1);

namespace App\Pay\LiZhi\Impl;

use App\Entity\PayEntity;
use App\Pay\Base;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Kernel\Exception\JSONException;

/**
 * Class Pay
 * @package App\Pay\Kvmpay\Impl
 */
class Pay extends Base implements \App\Pay\Pay
{

    /**
     * @return PayEntity
     * @throws JSONException
     */
    public function trade(): PayEntity
    {
        if (!$this->config['url']) {
            throw new JSONException("请配置荔枝付请求地址");
        }

        if (!$this->config['merchant_id']) {
            throw new JSONException("请配置荔枝付商户ID");
        }

        if (!$this->config['app_id']) {
            throw new JSONException("请配置荔枝付应用ID");
        }

        if (!$this->config['key']) {
            throw new JSONException("请配置荔枝付商户密钥");
        }

        $client = new Client(["verify" => false]);
        $postData = [
            'merchant_id' => $this->config['merchant_id'],
            'amount' => $this->amount,
            'channel_id' => $this->code,
            'app_id' => $this->config['app_id'],
            'notification_url' => $this->callbackUrl,
            'sync_url' => $this->returnUrl,
            'ip' => $this->clientIp,
            'out_trade_no' => $this->tradeNo
        ];
        $postData['sign'] = Signature::generateSignature($postData, $this->config['key']);
        try {
            $request = $client->post(trim($this->config['url'], "/") . '/order/trade', [
                "form_params" => $postData
            ]);
        } catch (GuzzleException $e) {
            throw new JSONException("请求荔枝付失败");
        }
        $contents = $request->getBody()->getContents();
        $json = json_decode((string)$contents, true);
        if ($json['code'] != 200) {
            throw new JSONException($json['msg']);
        }
        $url = $json['data']['url'];
        $payEntity = new PayEntity();
        $payEntity->setType(self::TYPE_REDIRECT);
        $payEntity->setUrl($url);
        return $payEntity;
    }
}