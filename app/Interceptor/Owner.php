<?php
declare(strict_types=1);

namespace App\Interceptor;


use App\Util\Client;
use App\Util\Context;
use JetBrains\PhpStorm\NoReturn;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\InterceptorInterface;

/**
 * Class Super
 * @package App\Interceptor
 */
class Owner implements InterceptorInterface
{

    /**
     * @param int $type
     */
    public function handle(int $type): void
    {
        $var = Context::get(\App\Consts\Manage::SESSION);
        $manageType = $var->type;
        if ($manageType != 0) {
            $this->kick("您暂时没有权限使用该功能", $type);
        }
    }

    /**
     * @param string $message
     * @param int $type
     */
    #[NoReturn] private function kick(string $message, int $type): void
    {
        if ($type == Interceptor::TYPE_VIEW) {
            Client::redirect("/admin/dashboard/index", $message, 0);
        } else {
            header('content-type:application/json;charset=utf-8');
            exit(json_encode(["code" => 0, "msg" => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}