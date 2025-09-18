<?php
declare(strict_types=1);

namespace App\Service;

use Kernel\Annotation\Bind;

/**
 * Interface ManageSSO
 * @package App\Service
 */
#[Bind(class: \App\Service\Bind\ManageSSO::class)]
interface ManageSSO
{
    /**
     * 登录
     * @param string $username
     * @param string $password
     * @param bool $remember
     * @return array
     */
    public function login(string $username, string $password, bool $remember = false): array;
}