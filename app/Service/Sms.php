<?php
declare(strict_types=1);

namespace App\Service;


interface Sms
{
    const CAPTCHA_REGISTER = 0x1;
    const CAPTCHA_FORGET = 0x2;
    const CAPTCHA_BIND_NEW = 0x3;

    /**
     * @param array $smsConfig
     * @param string $phone
     * @param string $templateCode
     * @param array $var
     */
    public function send(array $smsConfig, string $phone, string $templateCode, array $var = []): void;


    /**
     * @param string $phone
     * @param int $type
     */
    public function sendCaptcha(string $phone, int $type): void;

    /**
     * @param string $phone
     * @param int $type
     * @param int $code
     * @return bool
     */
    public function checkCaptcha(string $phone, int $type, int $code): bool;


    /**
     * @param string $phone
     * @param int $type
     */
    public function destroyCaptcha(string $phone, int $type): void;
}