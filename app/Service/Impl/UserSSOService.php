<?php
declare(strict_types=1);

namespace App\Service\Impl;


use App\Model\User;
use App\Service\UserSSO;
use App\Util\Client;
use App\Util\Date;

class UserSSOService implements UserSSO
{

    /**
     * @param User $user
     */
    public function loginSuccess(User $user): void
    {
        $user->last_login_time = $user->login_time;
        $user->login_time = Date::current();
        $user->last_login_ip = $user->login_ip;
        $user->login_ip = Client::getAddress();
        $user->save();
        $_SESSION[\App\Consts\User::SESSION] = $user->toArray();
        hook(\App\Consts\Hook::USER_API_AUTH_LOGIN_AFTER, $user);
    }
}