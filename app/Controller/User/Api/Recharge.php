<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Model\Pay;
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
        $pay = Pay::query()->orderBy("sort", "asc")->where("recharge", 1)->get(['id', 'name', 'icon', 'handle'])->toArray();
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