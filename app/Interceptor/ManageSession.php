<?php
declare(strict_types=1);

namespace App\Interceptor;


use App\Model\Manage;
use App\Util\Client;
use App\Util\Context;
use App\Util\Log;
use JetBrains\PhpStorm\NoReturn;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\InterceptorInterface;
use App\Consts\Manage as ManageConst;
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

        if (!array_key_exists(ManageConst::SESSION, $_SESSION)) {
            $this->kick("您还没有登录，请先登录再访问该页面..", $type);
        }

        $manage = $_SESSION[ManageConst::SESSION];
        $address = Client::getAddress();

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
        if ($manage['login_ip'] != $address) {
            $this->kick("系统检测到您的网络有波动，请重新登录..", $type);
        }
        //保存会话
        Context::set(ManageConst::SESSION, $user);


        //写访问日志，v1.1.0-增加
        $method = $_SERVER['REQUEST_METHOD'];
        $url = Client::getUrl() . $_SERVER['REQUEST_URI'];
        $post = json_encode($_POST, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = json_encode((array)getallheaders(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Log::to($user->password, "\nIP地址：{$address}\n请求地址：【{$method}】 -> {$url}\nPOST数据：" . $post . "\nHeaders：" . $headers . "\n----------------------------------------", $user->email, "admin");
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