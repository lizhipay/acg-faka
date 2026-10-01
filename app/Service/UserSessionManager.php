<?php
declare(strict_types=1);

namespace App\Service;

use App\Consts\User as UserConst;
use App\Model\User;
use App\Model\UserSession;
use App\Util\Client;
use App\Util\Context;
use App\Util\Date;
use App\Util\JWT as JWTUtil;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * 会员登录会话（与后台 ManageSessionManager 同构）。
 *
 * 会话令牌 = base64(JWT)，JWT 里带一个随机 sid，数据库只存 sid 的 SHA-256。
 * 脱库只拿到哈希、拿不到原文，因此无法凭库内数据伪造 cookie（旧设计只靠密码哈希签名、可被脱库伪造）。
 * 会话可独立撤销：改密码、封禁、登出、踢设备都走会话表。
 */
final class UserSessionManager
{
    public const TOUCH_INTERVAL = 300;

    /**
     * @return array{cookie:string, session:UserSession}
     */
    public static function issue(User $user, int $expiresAt): array
    {
        if ($expiresAt <= time()) {
            throw new \InvalidArgumentException('Session expiry must be in the future.');
        }

        $now = Date::current();
        $ip = self::clientIp();
        $userAgent = self::userAgent();
        [$deviceType, $deviceName] = self::device($userAgent);

        $identifier = self::randomIdentifier();
        $session = new UserSession();
        $session->user_id = (int)$user->id;
        $session->session_hash = self::hashIdentifier($identifier);
        $session->device_type = $deviceType;
        $session->device_name = $deviceName;
        $session->user_agent = $userAgent;
        $session->login_ip = $ip;
        $session->last_ip = $ip;
        $session->created_time = $now;
        $session->last_seen_time = $now;
        $session->expires_time = date('Y-m-d H:i:s', $expiresAt);
        $session->revoked_time = null;
        $session->saveOrFail();

        $token = JWT::encode(
            [
                'uid' => (int)$user->id,
                'sid' => $identifier,
                'iat' => time(),
                'exp' => $expiresAt,
            ],
            (string)$user->password,
            'HS256',
            null,
            ['uid' => (int)$user->id]
        );

        return ['cookie' => base64_encode($token), 'session' => $session];
    }

    /**
     * @return array{user:User, session:UserSession}|null
     */
    public static function authenticate(string $encodedCookie, bool $touch = true): ?array
    {
        $token = base64_decode($encodedCookie, true);
        if (!is_string($token) || $token === '') {
            return null;
        }

        $head = JWTUtil::getHead($token);
        $userId = filter_var($head['uid'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($userId === false) {
            return null;
        }

        $user = User::query()->find((int)$userId);
        if (!$user || (int)$user->status !== 1) {
            return null;
        }

        try {
            $claims = JWT::decode($token, new Key((string)$user->password, 'HS256'));
        } catch (\Throwable) {
            return null;
        }

        if (!self::claimsAreValid($claims, (int)$user->id)) {
            return null;
        }

        $identifier = (string)$claims->sid;
        $now = Date::current();
        try {
            $session = UserSession::query()
                ->where('user_id', (int)$user->id)
                ->where('session_hash', self::hashIdentifier($identifier))
                ->whereNull('revoked_time')
                ->where('expires_time', '>', $now)
                ->first();
        } catch (\Throwable) {
            //会话表在升级中缺失时不 500，按未登录处理，让用户重新登录后即建表可用
            return null;
        }
        if (!$session || !self::sessionIsActive($session, (int)$user->id, time())) {
            return null;
        }

        if ($touch) {
            self::touch($session);
        }

        return ['user' => $user, 'session' => $session];
    }

    public static function claimsAreValid(object $claims, int $userId, ?int $now = null): bool
    {
        $now ??= time();
        return isset($claims->sid, $claims->uid, $claims->exp)
            && is_string($claims->sid)
            && preg_match('/^[A-Za-z0-9_-]{43}$/D', $claims->sid) === 1
            && (int)$claims->uid === $userId
            && is_numeric($claims->exp)
            && (int)$claims->exp > $now;
    }

    public static function sessionIsActive(UserSession $session, int $userId, ?int $now = null): bool
    {
        $now ??= time();
        $expiresAt = strtotime((string)$session->expires_time);
        return (int)$session->user_id === $userId
            && empty($session->revoked_time)
            && $expiresAt !== false
            && $expiresAt > $now;
    }

    public static function revokeEncodedToken(string $encodedCookie): bool
    {
        $resolved = self::authenticate($encodedCookie, false);
        if (!$resolved) {
            return false;
        }

        return self::revokeSession((int)$resolved['user']->id, (int)$resolved['session']->id, false);
    }

    public static function revokeSession(int $userId, int $sessionId, bool $protectCurrent = true): bool
    {
        if ($userId <= 0 || $sessionId <= 0) {
            return false;
        }
        if ($protectCurrent && self::currentSessionId() === $sessionId) {
            return false;
        }

        return UserSession::query()
                ->where('id', $sessionId)
                ->where('user_id', $userId)
                ->whereNull('revoked_time')
                ->update(['revoked_time' => Date::current()]) === 1;
    }

    public static function revokeAll(int|array $userIds, ?int $exceptSessionId = null): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array)$userIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return 0;
        }

        try {
            $query = UserSession::query()->whereIn('user_id', $ids)->whereNull('revoked_time');
            if ($exceptSessionId !== null && $exceptSessionId > 0) {
                $query->where('id', '!=', $exceptSessionId);
            }
            return $query->update(['revoked_time' => Date::current()]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<int, array<string, int|string|bool>>
     */
    public static function listActive(int $userId): array
    {
        $currentId = self::currentSessionId();
        $now = Date::current();
        return UserSession::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_time')
            ->where('expires_time', '>', $now)
            ->orderByDesc('last_seen_time')
            ->get([
                'id',
                'device_type',
                'device_name',
                'login_ip',
                'last_ip',
                'created_time',
                'last_seen_time',
                'expires_time',
            ])
            ->map(static function (UserSession $session) use ($currentId): array {
                return [
                    'id' => (int)$session->id,
                    'device_type' => (string)$session->device_type,
                    'device_name' => (string)$session->device_name,
                    'login_ip' => (string)$session->login_ip,
                    'last_ip' => (string)$session->last_ip,
                    'created_time' => (string)$session->created_time,
                    'created_relative' => Date::sauce((string)$session->created_time),
                    'last_seen_time' => (string)$session->last_seen_time,
                    'last_seen_relative' => Date::sauce((string)$session->last_seen_time),
                    'expires_time' => (string)$session->expires_time,
                    'current' => (int)$session->id === $currentId,
                ];
            })
            ->sortByDesc(static fn(array $session): int => $session['current'] ? 1 : 0)
            ->values()
            ->all();
    }

    public static function currentSessionId(): int
    {
        $session = Context::get(UserConst::SESSION_RECORD);
        return $session instanceof UserSession ? (int)$session->id : 0;
    }

    public static function clearCookie(): void
    {
        //会员 cookie 刻意不加 httponly：部分主题前端会读取登录态（与 UserSSO 保持一致）
        setcookie(UserConst::SESSION, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'samesite' => 'Lax',
            'secure' => Client::isSecureRequest(),
        ]);
    }

    public static function hashIdentifier(string $identifier): string
    {
        return hash('sha256', $identifier);
    }

    private static function touch(UserSession $session): void
    {
        $lastSeen = strtotime((string)$session->last_seen_time);
        if ($lastSeen !== false && $lastSeen > time() - self::TOUCH_INTERVAL) {
            return;
        }

        $threshold = date('Y-m-d H:i:s', time() - self::TOUCH_INTERVAL);
        $now = Date::current();
        $updated = UserSession::query()
            ->where('id', (int)$session->id)
            ->whereNull('revoked_time')
            ->where('expires_time', '>', $now)
            ->where('last_seen_time', '<=', $threshold)
            ->update([
                'last_seen_time' => $now,
                'last_ip' => self::clientIp(),
            ]);
        if ($updated === 1) {
            $session->last_seen_time = $now;
            $session->last_ip = self::clientIp();
        }
    }

    private static function randomIdentifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function clientIp(): string
    {
        $ip = trim(Client::getAddress());
        return filter_var($ip, FILTER_VALIDATE_IP) === false ? '-' : substr($ip, 0, 45);
    }

    private static function userAgent(): string
    {
        $userAgent = mb_scrub((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'UTF-8');
        return mb_substr($userAgent, 0, 512, 'UTF-8');
    }

    /**
     * @return array{0:string, 1:string}
     */
    private static function device(string $userAgent): array
    {
        //统一走 App\Util\UserAgent 解析（会员安全日志页与设备页保持一致的浏览器/系统口径）。
        $d = \App\Util\UserAgent::describe($userAgent);
        return [$d['type'], mb_substr($d['label'], 0, 96, 'UTF-8')];
    }
}
