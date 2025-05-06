<?php
declare(strict_types=1);

namespace App\Interceptor;


use App\Consts\Manage as ManageConst;
use App\Model\Manage;
use App\Util\Client;
use App\Util\Context;
use App\Util\JWT;
use Firebase\JWT\Key;
use JetBrains\PhpStorm\NoReturn;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\InterceptorInterface;
use Kernel\Exception\JSONException;

/**
 * Class ManageSession
 * @package App\Interceptor
 */
class ManageSession implements InterceptorInterface
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

        if (!array_key_exists(ManageConst::SESSION, $_COOKIE)) {
            $this->kick($type);
        }


        $manageToken = base64_decode((string)$_COOKIE[ManageConst::SESSION]);


        if (empty($manageToken)) {
            $this->kick($type);
        }

        $head = JWT::getHead($manageToken);


        if (!isset($head['mid'])) {
            $this->kick($type);
        }

        $manage = Manage::query()->find($head['mid']);

        if (!$manage) {
            $this->kick($type);
        }

        try {
            $jwt = \Firebase\JWT\JWT::decode($manageToken, new Key($manage->password, 'HS256'));
        } catch (\Exception $e) {
            $this->kick($type);
        }

        if (
            $jwt->expire <= time() ||
            $manage->login_time != $jwt->loginTime ||
            $manage->login_ip != Client::getAddress() ||
            $manage->status != 1
        ) {
            $this->kick($type);
        }

        //保存会话
        Context::set(ManageConst::SESSION, $manage);
    }


    #[NoReturn] private function kick(int $type): void
    {
        setcookie(ManageConst::SESSION, "", time() - 3600, "/");
        if ($type == Interceptor::TYPE_VIEW) {
            Client::redirect("/admin/authentication/login?goto=" . urlencode($_SERVER['REQUEST_URI']), "登录会话过期，请重新登录..");
        } else {
            header('content-type:application/json;charset=utf-8');
            exit(json_encode(["code" => 0, "msg" => "登录会话过期，请重新登录.."], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}