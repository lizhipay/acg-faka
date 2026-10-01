<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Controller\Base\View\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserSession::class])]
class Security extends User
{

    /**
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    public function personal(): string
    {
        return $this->theme("个人资料", "PERSONAL", "User/Personal.html");
    }

    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function email(): string
    {
        return $this->theme("邮箱设置", "EMAIL", "User/Email.html");
    }

    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function phone(): string
    {
        return $this->theme("手机设置", "PHONE", "User/Phone.html");
    }

    /**
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    public function password(): string
    {
        return $this->theme("密码设置", "PASSWORD", "User/Password.html");
    }

    /**
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    public function twoFactor(): string
    {
        \App\Util\Schema::ensureUserTotp();
        return $this->theme("两步验证", "TWO_FACTOR", "User/TwoFactor.html", [
            "totp_bound" => !empty($this->getUser()->totp_secret),
        ]);
    }

    /**
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    public function passkey(): string
    {
        return $this->theme("通行密钥", "PASSKEY", "User/Passkey.html");
    }

    /**
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    public function device(): string
    {
        return $this->theme("登录设备", "DEVICE", "User/Device.html");
    }

    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function ipWhitelist(): string
    {
        return $this->theme("对接白名单", "IP_WHITELIST", "User/IpWhitelist.html");
    }

    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function log(): string
    {
        return $this->theme("安全日志", "SECURITY_LOG", "User/SecurityLog.html");
    }
}