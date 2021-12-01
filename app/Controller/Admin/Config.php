<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use App\Util\Theme;
use Kernel\Annotation\Interceptor;

#[Interceptor(ManageSession::class)]
class Config extends Manage
{

    private array $TOOLBAR = [
        ["name" => '🤡 基本设置', "url" => "/admin/config/index"],
        ["name" => "👹 短信设置", "url" => "/admin/config/sms"],
        ["name" => "👺 邮箱设置", "url" => "/admin/config/email"],
        ["name" => "🛡️ 其他设置", "url" => "/admin/config/other"],
    ];

    /**
     * Config constructor.
     */
    public function __construct()
    {
        $this->TOOLBAR = array_merge($this->TOOLBAR, (array)hook(\App\Consts\Hook::ADMIN_VIEW_CONFIG_TOOLBAR));
    }

    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function index(): string
    {

        return $this->render("网站设置", "Config/Setting.html", ["toolbar" => $this->TOOLBAR, "themes" => Theme::getThemes()]);
    }

    public function sms(): string
    {
        $smsConfig = json_decode(\App\Model\Config::get("sms_config"), true);
        return $this->render("短信设置", "Config/Sms.html", ["toolbar" => $this->TOOLBAR, "sms" => $smsConfig]);
    }

    public function email(): string
    {
        $emailConfig = json_decode(\App\Model\Config::get("email_config"), true);
        return $this->render("邮箱设置", "Config/Email.html", ["toolbar" => $this->TOOLBAR, "email" => $emailConfig]);
    }

    public function other(): string
    {
        return $this->render("其他设置", "Config/Other.html", ["toolbar" => $this->TOOLBAR]);
    }
}