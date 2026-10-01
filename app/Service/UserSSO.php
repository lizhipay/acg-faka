<?php
declare(strict_types=1);

namespace App\Service;


use App\Model\User;
use Kernel\Annotation\Bind;

#[Bind(class: \App\Service\Bind\UserSSO::class)]
interface UserSSO
{
    /** 密码正确但账号开启了两步验证：用这个业务码通知前端弹出验证码输入框 */
    const CODE_NEED_TOTP = 42001;

    /** 待二次验证登录态在 session 里的键 */
    const PENDING_KEY = 'USER_LOGIN_PENDING';

    /** 待验证态有效期（秒）：密码已过、只差动态码的窗口 */
    const PENDING_TTL = 300;

    /**
     * 登录成功入口：账号开启两步验证时不签发会话，改为暂存待验证态并抛 CODE_NEED_TOTP；
     * 否则直接签发。OAuth / 注册等所有登录路径都经此，2FA 不会被绕过。
     * @param User $user
     * @param bool $remember
     * @param bool $mfaSatisfied 本次凭证自身已满足多因素（做了用户验证的通行密钥），无需再过两步验证
     * @param string $via 登录方式：''=密码等常规方式，'passkey'=通行密钥（只影响安全日志）
     */
    public function loginSuccess(User $user, bool $remember = false, bool $mfaSatisfied = false, string $via = ''): void;

    /**
     * 真正签发会话（写会话表 + 下发 cookie + 触发 LOGIN_AFTER）。两步验证通过后由 totp 接口调用。
     * @param User $user
     * @param bool $remember
     * @param string $via 登录方式，同 loginSuccess
     */
    public function issue(User $user, bool $remember = false, string $via = ''): void;
}