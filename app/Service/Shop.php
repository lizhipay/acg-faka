<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\UserGroup;
use App\Service\Impl\ShopService;
use Kernel\Annotation\Bind;

#[Bind(class: ShopService::class)]
interface Shop
{

    /**
     * @param UserGroup|null $group
     * @return array
     */
    public function getCategory(?UserGroup $group): array;
}