<?php
declare(strict_types=1);

namespace App\Service\Impl;


use App\Consts\Hook;
use App\Model\Config;
use App\Model\User;
use App\Service\UserSSO;
use App\Util\Client;
use App\Util\Date;
use Firebase\JWT\JWT;

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

        $sessionExpire = Config::getSessionExpire();
        $jwt = base64_encode(JWT::encode(
            payload: [
                "expire" => time() + $sessionExpire,
                'loginTime' => $user->login_time
            ],
            key: $user->password,
            alg: 'HS256',
            head: ["uid" => $user->id]
        ));

        setcookie(\App\Consts\User::SESSION, $jwt, time() + $sessionExpire, "/");
        hook(Hook::USER_API_AUTH_LOGIN_AFTER, $user);
    }
}