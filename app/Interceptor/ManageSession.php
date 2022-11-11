<?php
declare(strict_types=1);

namespace App\Interceptor;


use App\Model\Manage;
use App\Util\Client;
use App\Util\Context;
use JetBrains\PhpStorm\NoReturn;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\InterceptorInterface;
use App\Consts\Manage as ManageConst;

/**
 * Class ManageSession
 * @package App\Interceptor
 */
class ManageSession implements InterceptorInterface
{

    #[NoReturn] public function handle(int $type): void
    {
        if (!array_key_exists(ManageConst::SESSION, $_SESSION)) {
            $this->kick("您还没有登录，请先登录再访问该页面..", $type);
        }

        $manage = $_SESSION[ManageConst::SESSION];

        if (empty($manage)) {
            $this->kick("登录会话过期，请重新登录..", $type);
        }

        $user = Manage::query()->find($manage['id']);
        //-----------------------------------
        if (!$user) {
            $this->kick("账号异常，请重新登录..", $type);
        }
        //-----------------------------------
        if ($manage['password'] != $user->password) {
            $this->kick("您的密码已修改，请重新登录..", $type);
        }
        //-----------------------------------
        if ($user->status != 1) {
            $this->kick("您的账号已被暂停使用..", $type);
        }
        //-----------------------------------
        if ($manage['login_time'] != $user->login_time) {
            $this->kick("您的账号在其他地方登录..", $type);
        }
        //-----------------------------------
        if ($manage['login_ip'] != Client::getAddress()) {
            $this->kick("系统检测到您的网络有波动，请重新登录..", $type);
        }
        //保存会话
        Context::set(ManageConst::SESSION, $user);
    }


    #[NoReturn] private function kick(string $message, int $type): void
    {
        $_SESSION['MANAGE_USER'] = null;
        unset($_SESSION['MANAGE_USER']);
        if ($type == Interceptor::TYPE_VIEW) {
            Client::redirect("/admin/authentication/login?goto=" . urlencode($_SERVER['REQUEST_URI']), $message);
        } else {
            header('content-type:application/json;charset=utf-8');
            exit(json_encode(["code" => 0, "msg" => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}