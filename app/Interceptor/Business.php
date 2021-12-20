<?php
declare(strict_types=1);

namespace App\Interceptor;


use App\Util\Client;
use App\Util\Context;
use JetBrains\PhpStorm\NoReturn;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\InterceptorInterface;

/**
 * Class Business
 * @package App\Interceptor
 */
class Business implements InterceptorInterface
{


    public function handle(int $type): void
    {
        $var = Context::get(\App\Consts\User::SESSION);
        if (!$var->businessLevel) {
            $this->kick("您暂时没有权限使用该功能，请开通店铺后在使用该功能。", $type);
        }

        if ($var->businessLevel->supplier != 1) {
            $this->kick("您暂时没有供货权限，请升级商户等级后在使用该功能", $type);
        }
    }


    /**
     * @param string $message
     * @param int $type
     */
    #[NoReturn] private function kick(string $message, int $type): void
    {
        if ($type == Interceptor::TYPE_VIEW) {
            Client::redirect("/user/business/index", $message);
        } else {
            header('content-type:application/json;charset=utf-8');
            exit(json_encode(["code" => 0, "msg" => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}