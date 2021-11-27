<?php
declare(strict_types=1);

namespace App\Controller\Shared;


use App\Controller\Base\API\Shared;
use App\Interceptor\SharedValidation;
use App\Model\Config;
use Kernel\Annotation\Interceptor;

#[Interceptor(SharedValidation::class, Interceptor::TYPE_API)]
class Authentication extends Shared
{
    /**
     * @throws \Kernel\Exception\JSONException
     */
    public function connect(): array
    {
        $shopName = Config::get("shop_name");
        return $this->json(200, "success", ["shopName" => $shopName, "balance" => $this->getUser()->balance]);
    }
}