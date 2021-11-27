<?php
declare(strict_types=1);

namespace App\Interceptor;


use App\Consts\User;
use App\Util\Context;
use JetBrains\PhpStorm\NoReturn;
use Kernel\Annotation\InterceptorInterface;

/**
 * 访客拦截器
 * Class UserVisitor
 * @package App\Interceptor
 */
class UserVisitor implements InterceptorInterface
{

    #[NoReturn] public function handle(int $type): void
    {
        if (!array_key_exists(User::SESSION, $_SESSION)) {
            return;
        }
        $session = $_SESSION[User::SESSION];
        if (empty($session)) {
            return;
        }
        $user = \App\Model\User::query()->find($session['id']);
        //-----------------------------------
        if (!$user) {
            return;
        }
        //-----------------------------------
        if ($session['password'] != $user->password) {
            return;
        }
        //-----------------------------------
        if ($user->status != 1) {
            return;
        }
        //-----------------------------------
        if ($session['login_time'] != $user->login_time) {
            return;
        }
        //-----------------------------------
        if ($session['login_ip'] != $user->login_ip) {
            return;
        }
        //保存会话
        Context::set(User::SESSION, $user);
    }
}