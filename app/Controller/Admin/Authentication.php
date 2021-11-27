<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Consts\Manage as ManageConst;
use App\Controller\Base\View\Manage;
use App\Util\Client;

/**
 * Class Authentication
 * @package App\Controller\Admin
 */
class Authentication extends Manage
{

    /**
     * 管理员登录
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    public function login(): string
    {
        if (array_key_exists(ManageConst::SESSION, $_SESSION) && isset($_SESSION[ManageConst::SESSION])) {
            Client::redirect("/admin/dashboard/index", "正在登录..", 1);
        }
        return $this->render("登录", "Authentication/Login.html");
    }

    public function logout()
    {
        $_SESSION['MANAGE_USER'] = null;
        unset($_SESSION['MANAGE_USER']);
        Client::redirect("/admin/authentication/login", "注销成功..", 1);
    }
}