<?php
declare(strict_types=1);

namespace App\Service;

use Kernel\Annotation\Bind;

#[Bind(class: \App\Service\Bind\Email::class)]
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
     * 最近一次发送失败的原因（SMTP 报错原文）。发送成功或未发送过时返回空串。
     * 用于「发送测试邮件」把失败原因展示给站长，而不是只报一句"发送失败"。
     * @return string
     */
    public function getLastError(): string;

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