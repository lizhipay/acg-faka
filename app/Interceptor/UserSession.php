<?php
declare(strict_types=1);

namespace App\Interceptor;


use App\Consts\User;
use App\Util\Client;
use App\Util\Context;
use App\Util\JWT;
use Firebase\JWT\Key;
use JetBrains\PhpStorm\NoReturn;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\InterceptorInterface;
use Kernel\Exception\JSONException;

/**
 * Class UserSession
 * @package App\Interceptor
 */
class UserSession implements InterceptorInterface
{

    /**
     * @throws JSONException
     */
    #[NoReturn] public function handle(int $type): void
    {
        if ($type == Interceptor::TYPE_API) {
            list($p1, $p2) = [(array)parse_url((string)$_SERVER['HTTP_REFERER']), parse_url(Client::getUrl())];
            if ($p1['host'] != $p2['host']) {
                throw new JSONException("当前页面会话失效，请刷新网页..");
            }
        }

        if (!array_key_exists(User::SESSION, $_COOKIE)) {
            $this->kick($type);
        }

        $userToken = base64_decode((string)$_COOKIE[User::SESSION]);

        if (!$userToken) {
            $this->kick($type);
        }

        $head = JWT::getHead($userToken);
        if (!isset($head['uid'])) {
            $this->kick($type);
        }

        $user = \App\Model\User::query()->find($head['uid']);


        if (!$user) {
            $this->kick($type);
        }

        try {
            $jwt = \Firebase\JWT\JWT::decode($userToken, new Key($user->password, 'HS256'));
        } catch (\Exception $e) {
            $this->kick($type);
        }


        if ($jwt->expire <= time() || $user->login_time != $jwt->loginTime || $user->status != 1) {
            $this->kick($type);
        }

        //保存会话
        Context::set(User::SESSION, $user);
    }

    /**
     * @param int $type
     */
    #[NoReturn] private function kick(int $type): void
    {
        setcookie(User::SESSION, "", time() - 3600, "/");
        if ($type == Interceptor::TYPE_VIEW) {
            Client::redirect("/user/authentication/login?goto=" . urlencode($_SERVER['REQUEST_URI']), "登录会话过期，请重新登录..");
        } else {
            header('content-type:application/json;charset=utf-8');
            exit(json_encode(["code" => 0, "msg" => "登录会话过期，请重新登录.."], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}