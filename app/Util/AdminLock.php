<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Config;
use App\Model\ManageSession;

/**
 * 后台闲置锁屏的伺服器端判定。
 *
 * 唯一真相是 manage_session.last_active_time：只有「用户交互 ping」与「解锁」会把它推进；
 * 普通 API 轮询不算活动（否则自动轮询会让会话永不锁）。是否锁定纯由时间计算得出，
 * 拦截器只读不写，避免每请求写库（CIFS 上很贵）。ping 一旦发现已越过锁点便拒绝续命，
 * 因此解锁是唯一能把活动时间推过锁点的途径，不需要额外的锁定布尔位。
 */
final class AdminLock
{
    /** ping 写入节流（秒） */
    private const PING_THROTTLE = 20;

    /**
     * 逾时分钟数。未设定视为 15 分钟；显式填 0 = 关闭锁屏。
     *
     * 铁律：拦截器每个后台请求都会读它。老站配置表里没有这个键时，Config::get 会
     * 每请求未命中 → flock + 查库，在 CIFS 上把整个后台串行化。故走 Config::cached()
     * 只读加密缓存、永不触库；缓存缺键（未设定 / 缓存不全）一律回退默认 15 分钟。
     */
    public static function timeoutMinutes(): int
    {
        $raw = Config::cached('admin_lock_timeout');
        $min = ($raw === null || $raw === '' ? 15 : (int)$raw);
        return $min > 0 ? $min : 0;
    }

    public static function timeoutSeconds(): int
    {
        return self::timeoutMinutes() * 60;
    }

    /**
     * 会话的有效活动时间戳。
     *
     * 只认 last_active_time；缺失（升级前的老会话）时回退到 created_time——**绝不回退 last_seen_time**：
     * 后者会被任何 API 轮询经 touch() 刷新，一旦用它当基准，闲置锁屏会被后台页面的自动轮询永久重置
     * （升级当下所有在线会话都会中招）。时间解析失败时回传 0（很久以前）→ 判定为锁定，方向 fail-closed。
     */
    public static function effectiveActiveTs(ManageSession $session): int
    {
        $ref = (string)($session->last_active_time ?: $session->created_time);
        $ts = $ref !== '' ? strtotime($ref) : false;
        return $ts === false ? 0 : $ts;
    }

    public static function isLocked(ManageSession $session, ?int $timeoutSeconds = null): bool
    {
        Schema::ensureManageSessionActivity();
        $timeout = $timeoutSeconds ?? self::timeoutSeconds();
        if ($timeout <= 0) {
            return false;
        }
        return (time() - self::effectiveActiveTs($session)) >= $timeout;
    }

    /**
     * 交互续命（ping）：仅在未锁定时把活动时间推进（节流）。
     * @return bool false = 已锁定（前端应弹出解锁），true = 已续命/仍活跃
     */
    public static function markActive(ManageSession $session): bool
    {
        if (self::isLocked($session)) {
            return false;
        }
        $lastTs = $session->last_active_time ? strtotime((string)$session->last_active_time) : 0;
        if ($lastTs && $lastTs > time() - self::PING_THROTTLE) {
            return true; //刚写过，跳过
        }
        $now = Date::current();
        ManageSession::query()
            ->where('id', (int)$session->id)
            ->whereNull('revoked_time')
            ->update(['last_active_time' => $now]);
        $session->last_active_time = $now;
        return true;
    }

    /**
     * 解锁：无条件把活动时间推到现在（唯一能越过锁点的途径）。
     */
    public static function refresh(ManageSession $session): void
    {
        Schema::ensureManageSessionActivity();
        $now = Date::current();
        ManageSession::query()
            ->where('id', (int)$session->id)
            ->update(['last_active_time' => $now]);
        $session->last_active_time = $now;
    }
}
