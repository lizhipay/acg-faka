<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Model\Pay;
use App\Util\Client;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class Recharge extends User
{

    #[Inject]
    private \App\Service\Recharge $recharge;

    /**
     * @return array
     */
    public function pay(): array
    {
        $equipment = 2;

        if (Client::isMobile()) {
            $equipment = 1;
        }

        if (Client::isWeChat()) {
            $equipment = 3;
        }

        $let = "(`equipment`=0 or `equipment`={$equipment})";
        //限额与手续费一并带出，前台据此预先置灰超限的接口（#1129）
        \App\Util\Schema::ensurePayAmountLimit();
        $pay = Pay::query()->orderBy("sort", "asc")->where("recharge", 1)->whereRaw($let)
            ->get(array_merge(['id', 'name', 'icon', 'handle'], \App\Util\PayLimit::LIST_COLUMNS))->toArray();
        hook(\App\Consts\Hook::USER_API_RECHARGE_PAY_LIST, $pay);
        $pay = \App\Util\PayLimit::present($pay);
        //支付方式名是站长自定义的动态文案，出口统一走 dyn 翻译
        $pay = \Kernel\Util\Lang::transList($pay, ['name']);
        return $this->json(200, 'success', $pay);
    }

    /**
     * @return array
     */
    public function trade(): array
    {
        $trade = $this->recharge->trade($this->getUser());
        return $this->json(200, 'success', $trade);
    }

}