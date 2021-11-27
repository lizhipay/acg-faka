<?php
declare(strict_types=1);

namespace App\Consts;


interface Email
{
    const CAPTCHA_REGISTER = "REG_EMAIL_%s";
    const CAPTCHA_FORGET = "FORGET_EMAIL_%s";
    const CAPTCHA_BIND_NEW = "BIND_NEW_EMAIL_%s";
    const CAPTCHA_BIND_OLD = "BIND_OLD_EMAIL_%s";
}