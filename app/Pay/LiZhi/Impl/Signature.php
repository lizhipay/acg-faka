<?php
declare(strict_types=1);

namespace App\Pay\LiZhi\Impl;

/**
 * Class Signature
 * @package App\Pay\Kvmpay\Impl
 */
class Signature implements \App\Pay\Signature
{

    /**
     * @param array $data
     * @param string $appKey
     * @return string
     */
    public static function generateSignature(array $data, string $appKey): string
    {
        unset($data['sign']);
        ksort($data);
        foreach ($data as $key => $val) {
            if ($val === '') {
                unset($data[$key]);
            }
        }
        return md5(urldecode(http_build_query($data) . "&key=" . $appKey));
    }

    /**
     * @inheritDoc
     */
    public function verification(array $data, array $config): bool
    {
        $generateSignature = self::generateSignature($data, $config['key']);
        if ($data['sign'] != $generateSignature) {
            return false;
        }
        return true;
    }

}