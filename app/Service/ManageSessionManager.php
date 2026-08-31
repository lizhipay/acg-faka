<?php
declare(strict_types=1);

namespace App\Service;

use App\Consts\Manage as ManageConst;
use App\Model\Manage;
use App\Model\ManageSession;
use App\Util\Client;
use App\Util\Date;
use App\Util\JWT as JWTUtil;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class ManageSessionManager
{
    public const TOUCH_INTERVAL = 300;

    /**
     * Create an independently revocable administrator session.
     *
     * @return array{cookie:string, session:ManageSession}
     */
    public static function issue(Manage $manage, int $expiresAt): array
    {
        if ($expiresAt <= time()) {
            throw new \InvalidArgumentException('Session expiry must be in the future.');
        }

        $now = Date::current();
        $ip = self::clientIp();
        $userAgent = self::userAgent();
        [$deviceType, $deviceName] = self::device($userAgent);

        $identifier = self::randomIdentifier();
        $session = new ManageSession();
        $session->manage_id = (int)$manage->id;
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
                'mid' => (int)$manage->id,
                'sid' => $identifier,
                'iat' => time(),
                'exp' => $expiresAt,
            ],
            (string)$manage->password,
            'HS256',
            null,
            ['mid' => (int)$manage->id]
        );

        return ['cookie' => base64_encode($token), 'session' => $session];
    }

    /**
     * Resolve and validate an encoded administrator JWT cookie.
     * Old tokens without sid fail closed.
     *
     * @return array{manage:Manage, session:ManageSession}|null
     */
    public static function authenticate(string $encodedCookie, bool $touch = true): ?array
    {
        $token = base64_decode($encodedCookie, true);
        if (!is_string($token) || $token === '') {
            return null;
        }

        $head = JWTUtil::getHead($token);
        $manageId = filter_var($head['mid'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($manageId === false) {
            return null;
        }

        $manage = Manage::query()->find((int)$manageId);
        if (!$manage || (int)$manage->status !== 1) {
            return null;
        }

        try {
            $claims = JWT::decode($token, new Key((string)$manage->password, 'HS256'));
        } catch (\Throwable) {
            return null;
        }

        if (!self::claimsAreValid($claims, (int)$manage->id)) {
            return null;
        }

        $identifier = (string)$claims->sid;
        $now = Date::current();
        $session = ManageSession::query()
            ->where('manage_id', (int)$manage->id)
            ->where('session_hash', self::hashIdentifier($identifier))
            ->whereNull('revoked_time')
            ->where('expires_time', '>', $now)
            ->first();
        if (!$session || !self::sessionIsActive($session, (int)$manage->id, time())) {
            return null;
        }

        if ($touch) {
            self::touch($session);
        }

        return ['manage' => $manage, 'session' => $session];
    }

    public static function claimsAreValid(object $claims, int $manageId, ?int $now = null): bool
    {
        $now ??= time();
        return isset($claims->sid, $claims->mid, $claims->exp)
            && is_string($claims->sid)
            && preg_match('/^[A-Za-z0-9_-]{43}$/D', $claims->sid) === 1
            && (int)$claims->mid === $manageId
            && is_numeric($claims->exp)
            && (int)$claims->exp > $now;
    }

    public static function sessionIsActive(ManageSession $session, int $manageId, ?int $now = null): bool
    {
        $now ??= time();
        $expiresAt = strtotime((string)$session->expires_time);
        return (int)$session->manage_id === $manageId
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

        return self::revokeSession((int)$resolved['manage']->id, (int)$resolved['session']->id, false);
    }

    public static function revokeSession(int $manageId, int $sessionId, bool $protectCurrent = true): bool
    {
        if ($manageId <= 0 || $sessionId <= 0) {
            return false;
        }
        if ($protectCurrent && self::currentSessionId() === $sessionId) {
            return false;
        }

        return ManageSession::query()
                ->where('id', $sessionId)
                ->where('manage_id', $manageId)
                ->whereNull('revoked_time')
                ->update(['revoked_time' => Date::current()]) === 1;
    }

    public static function revokeAll(int|array $manageIds, ?int $exceptSessionId = null): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array)$manageIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return 0;
        }

        $query = ManageSession::query()->whereIn('manage_id', $ids)->whereNull('revoked_time');
        if ($exceptSessionId !== null && $exceptSessionId > 0) {
            $query->where('id', '!=', $exceptSessionId);
        }
        return $query->update(['revoked_time' => Date::current()]);
    }

    /**
     * @return array<int, array<string, int|string|bool>>
     */
    public static function listActive(int $manageId): array
    {
        $currentId = self::currentSessionId();
        $now = Date::current();
        return ManageSession::query()
            ->where('manage_id', $manageId)
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
            ->map(static function (ManageSession $session) use ($currentId): array {
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
        $session = \App\Util\Context::get(ManageConst::SESSION_RECORD);
        return $session instanceof ManageSession ? (int)$session->id : 0;
    }

    public static function clearCookie(): void
    {
        setcookie(ManageConst::SESSION, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
    }

    public static function hashIdentifier(string $identifier): string
    {
        return hash('sha256', $identifier);
    }

    private static function touch(ManageSession $session): void
    {
        $lastSeen = strtotime((string)$session->last_seen_time);
        if ($lastSeen !== false && $lastSeen > time() - self::TOUCH_INTERVAL) {
            return;
        }

        $threshold = date('Y-m-d H:i:s', time() - self::TOUCH_INTERVAL);
        $now = Date::current();
        $updated = ManageSession::query()
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
        $type = preg_match('/iPad|Tablet/i', $userAgent) ? 'tablet'
            : (preg_match('/Android|iPhone|Mobile/i', $userAgent) ? 'mobile' : 'desktop');

        $os = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => $type === 'mobile' ? '移动设备' : '电脑',
        };
        $browser = match (true) {
            preg_match('/Edg\//i', $userAgent) === 1 => 'Edge',
            preg_match('/OPR\//i', $userAgent) === 1 => 'Opera',
            preg_match('/CriOS|Chrome\//i', $userAgent) === 1 => 'Chrome',
            preg_match('/FxiOS|Firefox\//i', $userAgent) === 1 => 'Firefox',
            preg_match('/Safari\//i', $userAgent) === 1 => 'Safari',
            default => '浏览器',
        };

        return [$type, mb_substr($os . ' · ' . $browser, 0, 96, 'UTF-8')];
    }
}
