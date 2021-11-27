<?php
declare(strict_types=1);

namespace App\Service;


use App\Model\User;

interface UserSSO
{
    /**
     * @param \App\Model\User $user
     */
    public function loginSuccess(User $user): void;
}