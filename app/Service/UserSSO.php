<?php
declare(strict_types=1);

namespace App\Service;


use App\Model\User;
use App\Service\Impl\UserSSOService;
use Kernel\Annotation\Bind;

#[Bind(class: UserSSOService::class)]
interface UserSSO
{
    /**
     * @param User $user
     */
    public function loginSuccess(User $user): void;
}