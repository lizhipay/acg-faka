<?php

namespace Yurun\PaySDK\Lib;

/**
 * 证书工具类.
 *
 * @source https://github.com/alipay/alipay-easysdk/blob/master/php/src/Kernel/Util/AntCertificationUtil.php 算法代码来自支付宝官方sdk
 */
class CertUtil
{
    /**
     * 从证书中提取序列号.
     *
     * @param $certPath
     *
     * @return string
     */
    public static function getCertSN($certPath)
    {
        $cert = file_get_contents($certPath);
        $ssl = openssl_x509_parse($cert);
        $SN = md5(static::array2string(array_reverse($ssl['issuer'])) . $ssl['serialNumber']);

        return $SN;
    }

    /**
     * 提取根证书序列号.
     *
     * @param $certPath  string 根证书
     *
     * @return string|null
     */
    public static function getRootCertSN($certPath)
    {
        $cert = file_get_contents($certPath);
        $array = explode('-----END CERTIFICATE-----', $cert);
        $SN = null;
        for ($i = 0; $i < \count($array) - 1; ++$i)
        {
            $ssl[$i] = openssl_x509_parse($array[$i] . '-----END CERTIFICATE-----');
            if (0 === strpos($ssl[$i]['serialNumber'], '0x'))
            {
                $ssl[$i]['serialNumber'] = static::hex2dec($ssl[$i]['serialNumber']);
            }
            if ('sha1WithRSAEncryption' == $ssl[$i]['signatureTypeLN'] || 'sha256WithRSAEncryption' == $ssl[$i]['signatureTypeLN'])
            {
                if (null == $SN)
                {
                    $SN = md5(static::array2string(array_reverse($ssl[$i]['issuer'])) . $ssl[$i]['serialNumber']);
                }
                else
                {
                    $SN = $SN . '_' . md5(static::array2string(array_reverse($ssl[$i]['issuer'])) . $ssl[$i]['serialNumber']);
                }
            }
        }

        return $SN;
    }

    /**
     * 数组转字符串.
     *
     * @param array $array
     *
     * @return string
     */
    public static function array2string($array)
    {
        $string = [];
        if ($array && \is_array($array))
        {
            foreach ($array as $key => $value)
            {
                $string[] = $key . '=' . $value;
            }
        }

        return implode(',', $string);
    }

    /**
     * 0x转高精度数字.
     *
     * @param $hex
     *
     * @return int|string
     */
    public static function hex2dec($hex)
    {
        $dec = 0;
        $len = \strlen($hex);
        for ($i = 3; $i <= $len; ++$i)
        {
            $dec = bcadd($dec, bcmul((string) (hexdec($hex[$i - 1])), bcpow('16', (string) ($len - $i))));
        }

        return $dec;
    }
}
