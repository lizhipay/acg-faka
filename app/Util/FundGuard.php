<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\User;
use Kernel\Exception\JSONException;
use Kernel\Util\Session;

/**
 * 资金操作二次验证（步进/sudo 模式）。
 *
 * 开启了两步验证、且打开了「资金操作二次验证」开关的会员，做资金操作（余额下单、提现、下级转账）前
 * 必须先过一次 TOTP；验证成功后在 session 里记一个短时窗口（默认 5 分钟），窗口内的资金操作免重复验证。
 * 未开 2FA 或未开该开关的会员完全不受影响。
 */
class FundGuard
{
    /** 需要资金二次验证时返回给前端的业务码（core util.post 据此弹码 → /fundVerify → 重放原请求） */
    public const CODE = 42002;

    private const SESSION_KEY = 'USER_FUND_VERIFIED';

    /** 步进窗口（秒） */
    public const WINDOW = 300;

    /**
     * 该会员的资金操作是否需要二次验证。
     */
    public static function required(?User $user): bool
    {
        if (!$user) {
            return false;
        }
        Schema::ensureUserTotp();
        return !empty($user->totp_secret) && (int)$user->fund_2fa === 1;
    }

    /**
     * 窗口内是否已验证过。
     */
    public static function verified(int $userId): bool
    {
        $v = Session::get(self::SESSION_KEY);
        return is_array($v)
            && (int)($v['uid'] ?? 0) === $userId
            && (int)($v['exp'] ?? 0) > time();
    }

    /**
     * 记一次验证通过，开启步进窗口。
     */
    public static function markVerified(int $userId): void
    {
        Session::set(self::SESSION_KEY, ['uid' => $userId, 'exp' => time() + self::WINDOW]);
    }

    /**
     * 资金操作入口守卫：需要且窗口内未验证时抛 CODE。
     * @throws JSONException
     */
    public static function assert(?User $user): void
    {
        if (!self::required($user)) {
            return;
        }
        if (self::verified((int)$user->id)) {
            return;
        }
        throw new JSONException("请完成两步验证后再进行资金操作", self::CODE);
    }
}
