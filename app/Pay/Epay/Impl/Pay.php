<?php
declare(strict_types=1);

namespace App\Pay\Epay\Impl;

use App\Entity\PayEntity;
use App\Pay\Base;
use Kernel\Exception\JSONException;

/**
 * Class Pay
 * @package App\Pay\Kvmpay\Impl
 */
class Pay extends Base implements \App\Pay\Pay
{

    /**
     * @return PayEntity
     * @throws \Kernel\Exception\JSONException
     */
    public function trade(): PayEntity
    {

        if (!$this->config['url']) {
            throw new JSONException("请配置易支付请求地址");
        }

        if (!$this->config['pid']) {
            throw new JSONException("请配置易支付商户ID");
        }

        if (!$this->config['key']) {
            throw new JSONException("请配置易支付商户密钥");
        }
 
        $param = [
            'pid' => $this->config['pid'],
            'name' => $this->tradeNo, //订单名称
            'type' => $this->code,
            'money' => $this->amount,
            'out_trade_no' => $this->tradeNo,
            'notify_url' => $this->callbackUrl,
            'return_url' => $this->returnUrl,
            'sitename' => $this->tradeNo,
        ];
        $param['sign'] = Signature::generateSignature($param, $this->config['key']);
        $param['sign_type'] = "MD5";
        $payEntity = new PayEntity();
        $payEntity->setType(self::TYPE_SUBMIT);
        $payEntity->setOption($param);
        $payEntity->setUrl(trim($this->config['url'], "/") . "/submit.php");
        return $payEntity;
    }
}