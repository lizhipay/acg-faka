<?php
declare(strict_types=1);

namespace App\Interceptor;


use App\Util\Client;
use App\Util\Context;
use JetBrains\PhpStorm\NoReturn;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\InterceptorInterface;

/**
 * 需要「已开通店铺（拥有商户等级）」的接口门禁——但不要求供货权限。
 *
 * 与 {@see \App\Controller\Base\API\User::businessValidation()}（Business::saveConfig 用它把关分站配置）
 * 口径一致，只校验 businessLevel。区别于 {@see Business} 拦截器：后者还要求 supplier=1（供货商），
 * 而分站主是「转售主站商品」的角色，未必具备供货权限，用 Business 会把正常分站主也挡在外面。
 *
 * 用于 {@see \App\Controller\User\Api\Master}：此前它只挂 UserSession，任何登录用户（无店铺）都能写
 * user_commodity/user_category、并触发 setCommodityAll* 的全表遍历写（CWE-862 + 写放大 DoS）。
 */
class Store implements InterceptorInterface
{
    public function handle(int $type): void
    {
        $var = Context::get(\App\Consts\User::SESSION);
        if (!$var->businessLevel) {
            $this->kick("您暂时没有权限使用该功能，请开通店铺后在使用该功能。", $type);
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
