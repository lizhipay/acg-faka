<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\UserGroup;

interface Shop
{

    /**
     * @param UserGroup|null $group
     * @return array
     */
    public function getCategory(?UserGroup $group): array;
}