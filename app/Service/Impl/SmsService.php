<?php
declare(strict_types=1);

namespace App\Service\Impl;


use App\Service\Sms;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;
use Mrgoon\AliSms\AliSms;

class SmsService implements Sms
{

    #[Inject]
    private AliSms $sms;

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

        $smsConfig = json_decode(\App\Model\Config::get("sms_config"), true);

        $config = [
            'access_key' => $smsConfig['accessKeyId'],
            'access_secret' => $smsConfig['accessKeySecret'],
            'sign_name' => $smsConfig['signName'],
        ];

        $response = $this->sms->sendSms($phone, $smsConfig['templateCode'], ['code' => $capthca], $config);

        if ($response->Message != "OK") {
            throw new JSONException($response->Message);
        }

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