#!/usr/bin/env php
<?php

// load autoload.php
$possibleFiles = [dirname(__DIR__, 3) . '/vendor/autoload.php', dirname(__DIR__, 5) . '/autoload.php', dirname(__DIR__, 4) . '/autoload.php'];
$file = null;
foreach ($possibleFiles as $possibleFile)
{
    if (file_exists($possibleFile))
    {
        $file = $possibleFile;
        break;
    }
}
if (null === $file)
{
    throw new RuntimeException('Unable to locate autoload.php file.');
}

require_once $file;
unset($possibleFiles, $possibleFile, $file);

use Yurun\PaySDK\Lib\Encrypt\AES256GCM;
use Yurun\PaySDK\Weixin\Params\PublicParams;
use Yurun\PaySDK\Weixin\SDKV3;

class CertificateDownloader
{
    const VERSION = '0.1.0';

    public function run()
    {
        $opts = $this->parseOpts();
        if (!$opts)
        {
            $this->printHelp();
            exit(1);
        }

        if (isset($opts['help']))
        {
            $this->printHelp();
            exit(0);
        }
        if (isset($opts['version']))
        {
            echo self::VERSION . "\n";
            exit(0);
        }

        $this->downloadCert($opts);
    }

    private function downloadCert($opts)
    {
        try
        {
            $publicParams = new PublicParams();
            $publicParams->mch_id = $opts['mchid'];
            $publicParams->certSerialNumber = $opts['serialno'];
            $publicParams->certPath = $opts['wechatpay-cert'];
            $publicParams->keyPath = $opts['privatekey'];

            $sdk = new SDKV3($publicParams);
            $request = new Yurun\PaySDK\Weixin\V3\Certificates\Request();
            $data = $sdk->execute($request);

            foreach ($data['data'] as $item)
            {
                $encCert = $item['encrypt_certificate'];
                $plain = AES256GCM::decryptToString($opts['key'], $encCert['associated_data'],
                    $encCert['nonce'], $encCert['ciphertext']);
                if (!$plain)
                {
                    echo "encrypted certificate decrypt fail!\n";
                    exit(1);
                }
                // 通过加载对证书进行简单合法性检验
                $cert = openssl_x509_read($plain); // 从字符串中加载证书
                if (!$cert)
                {
                    echo "downloaded certificate check fail!\n";
                    exit(1);
                }
                $plainCerts[] = $plain;
                $x509Certs[] = $cert;
            }

            // 输出证书信息，并保存到文件
            foreach ($data['data'] as $index => $item)
            {
                echo "Certificate {\n";
                echo '    Serial Number: ' . $item['serial_no'] . "\n";
                echo '    Not Before: ' . (new DateTime($item['effective_time']))->format('Y-m-d H:i:s') . "\n";
                echo '    Not After: ' . (new DateTime($item['expire_time']))->format('Y-m-d H:i:s') . "\n";
                echo "    Text: \n    " . str_replace("\n", "\n    ", $plainCerts[$index]) . "\n";
                echo "}\n";

                $outpath = $opts['output'] . \DIRECTORY_SEPARATOR . 'wechatpay_' . $item['serial_no'] . '.pem';
                file_put_contents($outpath, $plainCerts[$index]);
            }
        }
        catch (Exception $e)
        {
            echo "download failed, message=[{$e->getMessage()}]\n";
            echo $e;
            exit(1);
        }
    }

    private function parseOpts()
    {
        $opts = [
            ['key', 'k', true],
            ['mchid', 'm', true],
            ['privatekey', 'f', true],
            ['serialno', 's', true],
            ['output', 'o', true],
            ['wechatpay-cert', 'c', false],
        ];

        $shortopts = 'hV';
        $longopts = ['help', 'version'];
        foreach ($opts as $opt)
        {
            $shortopts .= $opt[1] . ':';
            $longopts[] = $opt[0] . ':';
        }
        $parsed = getopt($shortopts, $longopts);
        if (!$parsed)
        {
            return false;
        }

        $args = [];
        foreach ($opts as $opt)
        {
            if (isset($parsed[$opt[0]]))
            {
                $args[$opt[0]] = $parsed[$opt[0]];
            }
            elseif (isset($parsed[$opt[1]]))
            {
                $args[$opt[0]] = $parsed[$opt[1]];
            }
            elseif ($opt[2])
            {
                return false;
            }
        }

        if (isset($parsed['h']) || isset($parsed['help']))
        {
            $args['help'] = true;
        }
        if (isset($parsed['V']) || isset($parsed['version']))
        {
            $args['version'] = true;
        }

        return $args;
    }

    private function printHelp()
    {
        echo <<<EOD
Usage: 微信支付平台证书下载工具 [-hV] [-c=<wechatpayCertificatePath>]
                    -f=<privateKeyFilePath> -k=<apiV3key> -m=<merchantId>
                    -o=<outputFilePath> -s=<serialNo>
  -m, --mchid=<merchantId>   商户号
  -s, --serialno=<serialNo>  商户证书的序列号
  -f, --privatekey=<privateKeyFilePath>
                             商户的私钥文件
  -k, --key=<apiV3key>       ApiV3Key
  -c, --wechatpay-cert=<wechatpayCertificatePath>
                             微信支付平台证书，验证签名
  -o, --output=<outputFilePath>
                             下载成功后保存证书的路径
  -V, --version              Print version information and exit.
  -h, --help                 Show this help message and exit.

EOD;
    }
}

// main
(new CertificateDownloader())->run();
