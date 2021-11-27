<?php
declare(strict_types=1);

namespace App\Consts;


interface Sms
{
    const CAPTCHA_REGISTER = "REG_PHONE_%s";
    const CAPTCHA_FORGET = "FORGET_PHONE_%s";
    const CAPTCHA_BIND_NEW = "BIND_NEW_PHONE_%s";
}