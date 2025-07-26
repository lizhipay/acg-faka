<?php
//@Author: ChunXi <i@acg.sb>
//@License: MIT
//@Date: 2021/7/5
declare(strict_types=1);

namespace App\Service;

use App\Service\Impl\ManageSSOService;
use Kernel\Annotation\Bind;

/**
 * Interface ManageSSO
 * @package App\Service
 */
#[Bind(class: ManageSSOService::class)]
interface ManageSSO
{
    /**
     * 登录
     * @param string $username
     * @param string $password
     * @return array
     */
    public function login(string $username, string $password): array;
}