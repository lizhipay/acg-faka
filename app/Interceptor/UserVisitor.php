<?php
declare(strict_types=1);

namespace App\Interceptor;


use App\Consts\User;
use App\Service\UserSessionManager;
use App\Util\Context;
use Kernel\Annotation\InterceptorInterface;

/**
 * 访客拦截器
 * Class UserVisitor
 * @package App\Interceptor
 */
class UserVisitor implements InterceptorInterface
{
    public function handle(int $type): void
    {
        if (isset($_GET['from']) && \App\Util\Promotion::enabled() && \App\Model\User::query()->where("id", $_GET['from'])->exists()) {
            setcookie("promotion_from", $_GET['from'], time() + 10 * 365 * 24 * 60 * 60, "/");
        }

        if (!array_key_exists(User::SESSION, $_COOKIE)) {
            return;
        }

        $resolved = UserSessionManager::authenticate((string)$_COOKIE[User::SESSION]);
        if (!$resolved) {
            return;
        }

        //保存会话
        Context::set(User::SESSION, $resolved['user']);
        Context::set(User::SESSION_RECORD, $resolved['session']);
    }
}