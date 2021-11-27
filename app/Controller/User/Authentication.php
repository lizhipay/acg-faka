<?php
declare(strict_types=1);

namespace App\Controller\User;

use App\Controller\Base\View\User;
use App\Model\Config;
use App\Util\Client;

/**
 * Class Authentication
 * @package App\Controller\User
 */
class Authentication extends User
{

    /**
     * 管理员登录
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    public function login(): string
    {
        if (array_key_exists(\App\Consts\User::SESSION, $_SESSION) && isset($_SESSION[\App\Consts\User::SESSION])) {
            Client::redirect("/user/dashboard/index", "正在登录..", 1);
        }

        // return $this->render("登录", "Authentication/Login.html");
        return $this->theme("登录", "LOGIN", "Authentication/Login.html");
    }

    /**
     * @return string
     * @throws \Kernel\Exception\JSONException
     * @throws \Kernel\Exception\ViewException
     */
    public function register(): string
    {
        if (array_key_exists(\App\Consts\User::SESSION, $_SESSION) && isset($_SESSION[\App\Consts\User::SESSION])) {
            Client::redirect("/user/dashboard/index", "正在登录..", 1);
        }

        if (Config::get("registered_state") == 0) {
            Client::redirect("/user/authentication/login", "抱歉，注册暂时关闭", 1);
        }

        return $this->theme("注册", "REGISTER", "Authentication/Register.html");
    }

    /**
     * @return string
     * @throws \Kernel\Exception\JSONException
     * @throws \Kernel\Exception\ViewException
     */
    public function emailForget(): string
    {
        if ((int)Config::get("forget_type") == 1) {
            Client::redirect("/user/authentication/phoneForget", "请稍等..", 1);
        }

        return $this->theme("找回密码", "FORGET_EMAIL", "Authentication/ForgetEmail.html");
    }

    /**
     * @return string
     * @throws \Kernel\Exception\JSONException
     * @throws \Kernel\Exception\ViewException
     */
    public function phoneForget(): string
    {
        if ((int)Config::get("forget_type") == 0) {
            Client::redirect("/user/authentication/emailForget", "请稍等..", 1);
        }
        return $this->theme("找回密码", "FORGET_PHONE", "Authentication/ForgetPhone.html");
    }

    /**
     * 注销登录
     */
    public function logout()
    {
        $_SESSION[\App\Consts\User::SESSION] = null;
        unset($_SESSION[\App\Consts\User::SESSION]);
        Client::redirect("/user/authentication/login", "注销成功", 1);
    }
}