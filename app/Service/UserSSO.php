<?php
declare(strict_types=1);

namespace App\Service;


use App\Model\User;
use Kernel\Annotation\Bind;

#[Bind(class: \App\Service\Bind\UserSSO::class)]
interface UserSSO
{
    /**
     * @param User $user
     * @param bool $remember
     */
    public function loginSuccess(User $user, bool $remember = false): void;
}