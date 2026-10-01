<?php
declare(strict_types=1);

namespace App\Service\Bind;


use App\Consts\Hook;
use App\Model\Config;
use App\Model\User;
use App\Service\UserSessionManager;
use App\Util\Client;
use App\Util\Date;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Util\Session;

class UserSSO implements \App\Service\UserSSO
{
    //CODE_NEED_TOTP / PENDING_KEY / PENDING_TTL 定义在接口 App\Service\UserSSO 上，
    //控制器（引入的是接口）与本实现共用同一份，self:: 经接口继承可直接引用。

    /**
     * @param User $user
     * @param bool $remember
     * @param bool $mfaSatisfied
     * @param string $via
     * @throws JSONException
     * @throws RuntimeException
     */
    public function loginSuccess(User $user, bool $remember = false, bool $mfaSatisfied = false, string $via = ''): void
    {
        //开启两步验证：不签发会话，暂存待验证态，前端据 CODE_NEED_TOTP 弹出验证码框
        if (!$mfaSatisfied && !empty($user->totp_secret)) {
            Session::set(self::PENDING_KEY, [
                'uid' => (int)$user->id,
                'remember' => $remember,
                'exp' => time() + self::PENDING_TTL,
                'via' => $via,
            ]);
            throw new JSONException("该账号已开启两步验证，请输入验证码", self::CODE_NEED_TOTP);
        }

        $this->issue($user, $remember, $via);
    }

    /**
     * @param User $user
     * @param bool $remember
     * @param string $via
     * @throws RuntimeException
     */
    public function issue(User $user, bool $remember = false, string $via = ''): void
    {
        //签发新会话前清掉可能残留的待验证态
        Session::remove(self::PENDING_KEY);

        $user->last_login_time = $user->login_time;
        $user->login_time = Date::current();
        $user->last_login_ip = $user->login_ip;
        $user->login_ip = Client::getAddress();
        $user->save();

        $sessionExpire = $remember ? 86400 * 365 : Config::getSessionExpire();

        //升级不完整的老站兜底建表，避免登录 500
        \App\Util\Schema::ensureUserSessionTable();

        //会话令牌带随机 sid、数据库只存其哈希：脱库拿不到原文，无法伪造 cookie（旧设计仅凭密码哈希签名可被脱库伪造）。
        $issued = UserSessionManager::issue($user, time() + $sessionExpire);

        //防 CSRF：跨站请求不携带会话。用户端保留可读性（部分主题前端会读取登录态），故不加 httponly。
        setcookie(\App\Consts\User::SESSION, $issued['cookie'], [
            'expires' => time() + $sessionExpire,
            'path' => '/',
            'samesite' => 'Lax',
            'secure' => Client::isSecureRequest(),
        ]);
        hook(Hook::USER_API_AUTH_LOGIN_AFTER, $user);

        //安全日志：登录成功。风险按「本次登录 IP 是否异于上一次」判定（此刻 login_ip 已更新为本次）。
        $passkey = $via === 'passkey';
        \App\Model\UserLog::write(
            $user,
            $passkey ? 'login_passkey' : 'login',
            $passkey ? '通行密钥登录成功' : '登录成功',
            ((string)$user->last_login_ip !== '' && (string)$user->last_login_ip !== (string)$user->login_ip) ? 1 : 0
        );
    }
}
