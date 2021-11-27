<?php
declare(strict_types=1);

namespace App\Service;


interface Email
{
    const CAPTCHA_REGISTER = 0x1;
    const CAPTCHA_FORGET = 0x2;
    const CAPTCHA_BIND_NEW = 0x3;
    const CAPTCHA_BIND_OLD = 0x4;

    /**
     * @param string $email
     * @param string $title
     * @param string $content
     * @return bool
     */
    public function send(string $email, string $title, string $content): bool;

    /**
     * @param string $email
     * @param int $type
     * @return void
     */
    public function sendCaptcha(string $email, int $type): void;


    /**
     * @param string $email
     * @param int $type
     * @param int $code
     * @return bool
     */
    public function checkCaptcha(string $email, int $type, int $code): bool;

    /**
     * @param string $email
     * @param int $type
     */
    public function destroyCaptcha(string $email, int $type): void;
}