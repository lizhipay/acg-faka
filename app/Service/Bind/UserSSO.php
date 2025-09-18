<?php
declare(strict_types=1);

namespace App\Service\Bind;


use App\Consts\Hook;
use App\Model\Config;
use App\Model\User;
use App\Util\Client;
use App\Util\Date;
use Firebase\JWT\JWT;
use Kernel\Exception\RuntimeException;

class UserSSO implements \App\Service\UserSSO
{

    /**
     * @param User $user
     * @param bool $remember
     * @throws RuntimeException
     */
    public function loginSuccess(User $user, bool $remember = false): void
    {
        $user->last_login_time = $user->login_time;
        $user->login_time = Date::current();
        $user->last_login_ip = $user->login_ip;
        $user->login_ip = Client::getAddress();
        $user->save();

        $sessionExpire = Config::getSessionExpire();

        if ($remember) $sessionExpire = 86400 * 365;

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