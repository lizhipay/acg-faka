<?php
declare(strict_types=1);

namespace App\Service\Impl;


use App\Service\Sms;
use App\Util\Http;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;
use Mrgoon\AliSms\AliSms;

class SmsService implements Sms
{

    #[Inject]
    private AliSms $sms;


    /**
     * 腾讯云短信发送V1接口
     * @param array $smsConfig
     * @param string $phone
     * @param string $templateCode
     * @param array $var
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Kernel\Exception\JSONException
     */
    private function tencentSms(array $smsConfig, string $phone, string $templateCode, array $var = [])
    {
        $host = "sms.tencentcloudapi.com";
        $param = [
            "Nonce" => 11886,
            "Timestamp" => time(),
            "Region" => "ap-guangzhou",
            "SecretId" => $smsConfig['tencentSecretId'],
            "Version" => "2021-01-11",
            "Action" => "SendSms",
            "SmsSdkAppId" => $smsConfig['tencentSdkAppId'],
            "SignName" => $smsConfig['tencentSignName'],
            "TemplateId" => $templateCode,
            "PhoneNumberSet.0" => "+86" . $phone,
        ];
        foreach ($var as $index => $item) {
            $param["TemplateParamSet." . $index] = $item;
        }
        ksort($param);
        $signStr = "GET" . $host . "/?";
        foreach ($param as $key => $value) {
            $signStr = $signStr . $key . "=" . $value . "&";
        }
        $signStr = substr($signStr, 0, -1);
        $signature = base64_encode(hash_hmac("sha1", $signStr, $smsConfig['tencentSecretKey'], true));
        $param["Signature"] = $signature;
        $paramStr = "";
        foreach ($param as $key => $value) {
            $paramStr = $paramStr . $key . "=" . urlencode((string)$value) . "&";
        }
        $paramStr = substr($paramStr, 0, -1);
        $response = \App\Util\Http::make()->get("https://" . $host . "/?{$paramStr}");
        $json = json_decode((string)$response->getBody()->getContents(), true);
        if ((string)$json['Response']['SendStatusSet'][0]['Code'] != "Ok") {
            throw new JSONException("短信发送失败");
        }
    }


    /**
     * 发送短信
     * @param array $smsConfig
     * @param string $phone
     * @param string $templateCode
     * @param array $var
     * @throws \Kernel\Exception\JSONException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function send(array $smsConfig, string $phone, string $templateCode, array $var = []): void
    {
        $platform = (int)$smsConfig['platform'];
        if ($platform == 0) {
            //阿里云
            $config = [
                'access_key' => $smsConfig['accessKeyId'],
                'access_secret' => $smsConfig['accessKeySecret'],
                'sign_name' => $smsConfig['signName'],
            ];
            $response = $this->sms->sendSms($phone, $templateCode, $var, $config);
            if ($response->Message != "OK") {
                throw new JSONException($response->Message);
            }
            //发送成功
        } elseif ($platform == 1) {
            $this->tencentSms($smsConfig, $phone, $templateCode, $var);
        } elseif ($platform == 2) {
            $response = Http::make()->get("https://api.smsbao.com/sms?u={$smsConfig['dxbao_username']}&p=" . md5((string)$smsConfig['dxbao_password']) . "&m={$phone}&c={$templateCode}");
            $contents = $response->getBody()->getContents();
            if ($contents != "0") {
                throw new JSONException("短信发送失败");
            }
        }
    }


    /**
     * @param string $phone
     * @param int $type
     * @throws \Kernel\Exception\JSONException
     */
    public function sendCaptcha(string $phone, int $type): void
    {
        $capthca = mt_rand(100000, 999999);
        $key = match ($type) {
            Sms::CAPTCHA_REGISTER => sprintf(\App\Consts\Sms::CAPTCHA_REGISTER, $phone),
            Sms::CAPTCHA_FORGET => sprintf(\App\Consts\Sms::CAPTCHA_FORGET, $phone),
            Sms::CAPTCHA_BIND_NEW => sprintf(\App\Consts\Sms::CAPTCHA_BIND_NEW, $phone),
        };

        if (isset($_SESSION[$key])) {
            if ($_SESSION[$key]['time'] + 60 > time()) {
                throw new JSONException("验证码发送频繁，请稍后再试");
            }
        }

        //验证码发送嘎嘎
        $smsConfig = (array)json_decode(\App\Model\Config::get("sms_config"), true);
        $platform = (int)$smsConfig['platform'];

        $templateCode = match ($platform) {
            0 => $smsConfig['templateCode'], //阿里云
            1 => $smsConfig['tencentTemplateId'], //腾讯云
            2 => str_replace("{code}", (string)$capthca, $smsConfig['dxbao_template'])//短信宝
        };

        $var = match ($platform) {
            0 => ['code' => $capthca], //阿里云
            1 => [(string)$capthca], //腾讯云
            2 => [], //短信宝
        };

        //统一短信发送接口
        $this->send($smsConfig, $phone, $templateCode, $var);

        $_SESSION[$key] = ["time" => time(), "code" => $capthca];
    }

    /**
     * @param string $phone
     * @param int $type
     * @param int $code
     * @return bool
     */
    public function checkCaptcha(string $phone, int $type, int $code): bool
    {
        $key = match ($type) {
            Sms::CAPTCHA_REGISTER => sprintf(\App\Consts\Sms::CAPTCHA_REGISTER, $phone),
            Sms::CAPTCHA_FORGET => sprintf(\App\Consts\Sms::CAPTCHA_FORGET, $phone),
            Sms::CAPTCHA_BIND_NEW => sprintf(\App\Consts\Sms::CAPTCHA_BIND_NEW, $phone),
        };

        if (!isset($_SESSION[$key])) {
            return false;
        }

        if ($_SESSION[$key]['code'] != $code) {
            return false;
        }

        if ($_SESSION[$key]['time'] + 300 < time()) {
            return false;
        }

        return true;
    }

    /**
     * @param string $phone
     * @param int $type
     */
    public function destroyCaptcha(string $phone, int $type): void
    {
        $key = match ($type) {
            Sms::CAPTCHA_REGISTER => sprintf(\App\Consts\Sms::CAPTCHA_REGISTER, $phone),
            Sms::CAPTCHA_FORGET => sprintf(\App\Consts\Sms::CAPTCHA_FORGET, $phone),
            Sms::CAPTCHA_BIND_NEW => sprintf(\App\Consts\Sms::CAPTCHA_BIND_NEW, $phone),
        };
        unset($_SESSION[$key]);
    }


}