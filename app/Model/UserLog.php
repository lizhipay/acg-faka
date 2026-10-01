<?php
declare(strict_types=1);

namespace App\Model;

use App\Util\Client;
use App\Util\Date;
use App\Util\Schema;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;

/**
 * 会员安全稽核日志（与后台 manage_log 同构，但按会员归属）。
 * 只记账号安全与资金相关事件：登录/失败/退出、改密/改绑、两步验证开关、资金二次验证、设备退出、提现、转账。
 * 不记一般浏览/查询，也不落敏感值（邮箱/手机/地址明文），降低脱库负债。
 *
 * @property int $id
 * @property int $user_id
 * @property string $username
 * @property string $action
 * @property string $content
 * @property string $create_time
 * @property string $create_ip
 * @property string $ua
 * @property int $risk
 */
class UserLog extends Model
{
    protected $table = "user_log";

    public $timestamps = false;

    protected $casts = ['id' => 'integer', 'user_id' => 'integer', 'risk' => 'integer'];

    /**
     * 写一条会员安全日志。全程 fail-safe：任何异常都不得影响正在进行的业务操作。
     *
     * @param User|int $user 会员对象或会员ID
     * @param string $action 事件类型码（见前端 user/log.js 的标签表）
     * @param string $content 简短中文详情（不含敏感明文）
     * @param int|null $risk 覆盖风险判定；为 null 时按「本次 IP 是否异于登录 IP」推断
     */
    public static function write(User|int $user, string $action, string $content = '', ?int $risk = null): void
    {
        try {
            //建表兜底只在非事务上下文执行：事务内跑 DDL 会触发隐式提交，破坏调用方事务（如提现/转账）。
            if (Manager::connection()->transactionLevel() === 0) {
                Schema::ensureUserLogTable();
            }

            if (is_int($user)) {
                $uid = $user;
                $username = '';
                $refIp = '';
            } else {
                $uid = (int)($user->id ?? 0);
                $username = (string)($user->username ?? '');
                $refIp = (string)($user->login_ip ?: $user->last_login_ip ?: '');
            }
            if ($uid <= 0) {
                return;
            }

            $ip = (string)Client::getAddress();
            if ($risk === null) {
                $risk = ($refIp !== '' && $refIp !== $ip) ? 1 : 0;
            }

            $log = new UserLog();
            $log->user_id = $uid;
            $log->username = mb_substr($username, 0, 32);
            $log->action = mb_substr($action, 0, 32);
            $log->content = mb_substr($content, 0, 255);
            $log->create_time = Date::current();
            $log->create_ip = mb_substr($ip, 0, 64);
            $log->ua = mb_substr((string)Client::getUserAgent(), 0, 255);
            $log->risk = $risk ? 1 : 0;
            $log->save();
        } catch (\Throwable $e) {
        }
    }
}
