<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Consts\Manage as ManageConst;
use App\Controller\Base\View\Manage;
use App\Util\Client;
use Kernel\Exception\ViewException;

/**
 * Class Authentication
 * @package App\Controller\Admin
 */
class Authentication extends Manage
{

    /**
     * 管理员登录
     * @return string
     * @throws ViewException
     */
    public function login(): string
    {
        if (array_key_exists(ManageConst::SESSION, $_COOKIE) && isset($_COOKIE[ManageConst::SESSION])) {
            Client::redirect("/admin/dashboard/index", "正在登录..", 1);
        }
        return $this->render("登录", "Authentication/Login.html");
    }

    public function logout()
    {
        setcookie(ManageConst::SESSION, "", time() - 3600, "/");
        Client::redirect("/admin/authentication/login", "注销成功..", 1);
    }
}