<?php
declare(strict_types=1);

namespace App\Service\Bind;


use App\Consts\Manage as ManageConst;
use App\Model\Manage;
use App\Model\ManageLog;
use App\Util\Client;
use App\Util\Date;
use App\Util\Str;
use Firebase\JWT\JWT;
use Kernel\Exception\JSONException;

/**
 * Class ManageSSOService
 * @package App\Service\Impl
 */
class ManageSSO implements \App\Service\ManageSSO
{

    /**
     * @param string $username
     * @param string $password
     * @param bool $remember
     * @return array
     * @throws JSONException
     */
    public function login(string $username, string $password, bool $remember = false, string $code = ''): array
    {
        $manage = Manage::query()->where("email", $username)->first();
        if (!$manage) {
            throw new JSONException("该邮箱不存在");
        }
        if (!hash_equals((string)$manage->password, Str::generatePassword($password, $manage->salt))) {
            ManageLog::log($manage, "登录失败：密码错误");
            throw new JSONException("密码错误");
        }

        //谷歌验证器：已绑定则必须校验动态码（密码通过后才校验，避免暴露 2FA 是否开启）
        if (!empty($manage->google_secret)) {
            if (!\App\Util\Totp::verify((string)$manage->google_secret, $code)) {
                ManageLog::log($manage, "登录失败：谷歌验证码错误");
                throw new JSONException("谷歌验证码错误");
            }
        }

        if ($manage->status != 1) {
            throw new JSONException("账号已被暂停使用");
        }

        if ($manage->type == 2 && Date::isNight()) {
            throw new JSONException("您是白班哦，请注意休息。");
        }

        if ($manage->type == 3 && !Date::isNight()) {
            throw new JSONException("您是夜班哦，请注意休息。");
        }

        $manage->last_login_time = $manage->login_time;
        $manage->last_login_ip = $manage->login_ip;
        $manage->login_time = Date::current();
        $manage->login_ip = Client::getAddress();
        $manage->save();

        ManageLog::log($manage, "登录了后台");

        $expire = 86400;

        if ($remember) {
            $expire *= 365;
        }

        $payload = array(
            "expire" => time() + $expire,
            "loginTime" => $manage->login_time
        );

        $jwt = base64_encode(JWT::encode(
            payload: $payload,
            key: $manage->password,
            alg: 'HS256',
            head: ["mid" => $manage->id]
        ));

        setcookie(ManageConst::SESSION, $jwt, [
            'expires' => time() + $expire,
            'path' => '/',
            'httponly' => true,               //禁止 JS 读取会话 Cookie（防 XSS 窃取/日志泄露复用）
            'samesite' => 'Lax',              //防 CSRF：跨站请求不携带后台会话
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);

        return ["username" => $manage->email, "avatar" => $manage->avatar, "token" => $jwt];
    }
}