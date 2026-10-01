<?php
declare(strict_types=1);

namespace App\Controller\User;

use App\Controller\Base\View\User;
use App\Model\Config;
use App\Util\Client;
use Kernel\Exception\ViewException;

/**
 * Class Authentication
 * @package App\Controller\User
 */
class Authentication extends User
{

    /**
     * 管理员登录
     * @return string
     * @throws ViewException|\ReflectionException
     */
    public function login(): string
    {
        if (array_key_exists(\App\Consts\User::SESSION, $_COOKIE) && isset($_COOKIE[\App\Consts\User::SESSION])) {
            Client::redirect("/user/dashboard/index", "正在登录..", 1);
        }

        // return $this->render("登录", "Authentication/Login.html");
        return $this->theme("登录", "LOGIN", "Authentication/Login.html");
    }

    /**
     * @return string
     * @throws ViewException|\ReflectionException
     */
    public function register(): string
    {
        if (array_key_exists(\App\Consts\User::SESSION, $_COOKIE) && isset($_COOKIE[\App\Consts\User::SESSION])) {
            Client::redirect("/user/dashboard/index", "正在登录..", 1);
        }

        if (Config::get("registered_state") == 0) {
            Client::redirect("/user/authentication/login", "抱歉，注册暂时关闭", 1);
        }

        return $this->theme("注册", "REGISTER", "Authentication/Register.html");
    }

    /**
     * @return string
     * @throws ViewException
     * @throws \ReflectionException
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
     * @throws ViewException
     * @throws \ReflectionException
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
    public function logout(): void
    {
        if (isset($_COOKIE[\App\Consts\User::SESSION])) {
            $cookie = (string)$_COOKIE[\App\Consts\User::SESSION];
            //先按 cookie 解析出会员再吊销，才能把「退出登录」记到对应会员名下（logout 不走鉴权拦截器）。
            $logoutResolved = \App\Service\UserSessionManager::authenticate($cookie, false);
            \App\Service\UserSessionManager::revokeEncodedToken($cookie);
            //authenticate 返回 ['user'=>User, 'session'=>...]，UserLog::write 要的是 User 本体，别把整个数组传进去。
            if ($logoutResolved && isset($logoutResolved['user'])) {
                \App\Model\UserLog::write($logoutResolved['user'], 'logout', '退出登录');
            }
        }
        \App\Service\UserSessionManager::clearCookie();
        Client::redirect("/user/authentication/login", "注销成功", 1);
    }
}