<?php
declare(strict_types=1);

namespace App\Pay\Alipay\Impl;


use App\Entity\PayEntity;
use App\Pay\Base;
use Kernel\Exception\JSONException;

class Pay extends Base implements \App\Pay\Pay
{

    /**
     * @throws \Kernel\Exception\JSONException
     */
    public function trade(): PayEntity
    {
        if ($this->code == 1) {
            return $this->face();
        } else if ($this->code == 2) {
            return $this->pc();
        } else if ($this->code == 3) {
            return $this->wap();
        } else {
            throw new JSONException("非法请求");
        }
    }

    /**
     * @return PayEntity
     * @throws \Kernel\Exception\JSONException
     */
    private function face(): PayEntity
    {
        $params = new \Yurun\PaySDK\AlipayApp\Params\PublicParams;
        $params->appID = $this->config['app_id'];
        $params->appPrivateKey = $this->config['private_key'];
        $params->appPublicKey = $this->config['public_key'];
        $pay = new \Yurun\PaySDK\AlipayApp\SDK($params);
        // 支付接口
        $request = new \Yurun\PaySDK\AlipayApp\FTF\Params\QR\Request;
        $request->notify_url = $this->callbackUrl;
        $request->businessParams->out_trade_no = $this->tradeNo; // 商户订单号
        $request->businessParams->total_amount = $this->amount; // 价格
        $request->businessParams->subject = $this->tradeNo; //商品标题
        try {
            $data = $pay->execute($request);
            $qrcode = (string)$data['alipay_trade_precreate_response']['qr_code'];
            if ($qrcode == '') {
                $this->log("没有获取到二维码");
                throw new JSONException("下单失败，请联系客服");
            }
            $payEntity = new PayEntity();
            $payEntity->setType(self::TYPE_LOCAL_RENDER);
            $payEntity->setUrl($qrcode);
            $payEntity->setOption(['returnUrl' => $this->returnUrl]);
            return $payEntity;
        } catch (\Exception $e) {
            $this->log($e->getMessage());
            throw new JSONException($e->getMessage());
        }
    }

    /**
     * @return PayEntity
     * @throws JSONException
     */
    private function pc(): PayEntity
    {
        $params = new \Yurun\PaySDK\AlipayApp\Params\PublicParams;
        $params->appID = $this->config['app_id'];
        $params->appPrivateKey = $this->config['private_key'];
        $params->appPublicKey = $this->config['public_key'];
        $pay = new \Yurun\PaySDK\AlipayApp\SDK($params);
        // 支付接口
        $request = new \Yurun\PaySDK\AlipayApp\Page\Params\Pay\Request;
        $request->notify_url = $this->callbackUrl;
        $request->return_url = $this->returnUrl;
        $request->businessParams->out_trade_no = $this->tradeNo; // 商户订单号
        $request->businessParams->total_amount = $this->amount; // 价格
        $request->businessParams->subject = $this->tradeNo; // 商品标题
        try {
            $pay->prepareExecute($request, $url);
            if ($url == '') {
                throw new JSONException("下单失败，请联系客服");
            }
            $payEntity = new PayEntity();
            $payEntity->setType(self::TYPE_REDIRECT);
            $payEntity->setUrl($url);
            return $payEntity;
        } catch (\Exception $e) {
            throw new JSONException("下单失败，请联系客服");
        }
    }

    /**
     * @return PayEntity
     * @throws JSONException
     */
    private function wap(): PayEntity
    {
        $params = new \Yurun\PaySDK\AlipayApp\Params\PublicParams;
        $params->appID = $this->config['app_id'];
        $params->appPrivateKey = $this->config['private_key'];
        $params->appPublicKey = $this->config['public_key'];
        $pay = new \Yurun\PaySDK\AlipayApp\SDK($params);
        // 支付接口
        $request = new \Yurun\PaySDK\AlipayApp\Wap\Params\Pay\Request;
        $request->notify_url = $this->callbackUrl;
        $request->return_url = $this->returnUrl;
        $request->businessParams->out_trade_no = $this->tradeNo; // 商户订单号
        $request->businessParams->total_amount = $this->amount; // 价格
        $request->businessParams->subject = $this->tradeNo;// 商品标题
        try {
            $pay->prepareExecute($request, $url);
            if ($url == '') {
                throw new JSONException("下单失败，请联系客服");
            }
            $payEntity = new PayEntity();
            $payEntity->setType(self::TYPE_REDIRECT);
            $payEntity->setUrl($url);
            return $payEntity;
        } catch (\Exception $e) {
            throw new JSONException("下单失败，请联系客服");
        }
    }
}