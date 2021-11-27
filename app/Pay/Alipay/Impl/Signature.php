<?php
declare(strict_types=1);

namespace App\Pay\Alipay\Impl;


class Signature implements \App\Pay\Signature
{
    /**
     * @param array $data
     * @param array $config
     * @return bool
     */
    public function verification(array $data, array $config): bool
    {
        $params = new \Yurun\PaySDK\AlipayApp\Params\PublicParams;
        $params->appPrivateKey = $config['private_key'];
        $params->appPublicKey = $config['public_key'];
        $pay = new \Yurun\PaySDK\AlipayApp\SDK($params);
        try {
            if ($pay->verifyCallback($data)) {
                return true;
            } else {
                return false;
            }
        } catch (\Exception $e) {
            return false;
        }
    }
}