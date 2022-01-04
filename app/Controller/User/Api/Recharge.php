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

        $let = "(`equipment`=0 or `equipment`={$equipment})";
        $pay = Pay::query()->orderBy("sort", "asc")->where("recharge", 1)->whereRaw($let)->get(['id', 'name', 'icon', 'handle'])->toArray();
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