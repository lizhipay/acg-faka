<?php
declare(strict_types=1);

namespace App\Service\Bind;


use App\Consts\Manage as ManageConst;
use App\Model\Manage;
use App\Model\ManageLog;
use App\Service\ManageSessionManager;
use App\Util\Client;
use App\Util\Date;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Exception\JSONException;

/**
 * Class ManageSSOService
 * @package App\Service\Impl
 */
class ManageSSO implements \App\Service\ManageSSO
{

    /**
     * 账号已绑定谷歌验证器但本次未提交动态码：前端据此弹出谷歌验证码输入框。
     * 仅在邮箱+密码验证通过后抛出，不向未授权者泄露 2FA 开启状态
     */
    public const CODE_NEED_TOTP = 42001;

    /**
     * @param string $username
     * @param string $password
     * @param bool $remember
     * @param string $code
     * @param string|null $rawPassword 未清洗的原始密码输入，用于旧清洗管线的兼容比对（#833）
     * @return array
     * @throws JSONException
     */
    public function login(string $username, string $password, bool $remember = false, string $code = '', ?string $rawPassword = null): array
    {
        $failedAudit = null;
        try {
            $login = DB::transaction(function () use (
                $username,
                $password,
                $remember,
                $code,
                $rawPassword,
                &$failedAudit
            ): array {
                // Lock the account through verification and session creation. A
                // concurrent password/status/2FA reset can no longer issue a
                // session from stale security settings after global revocation.
                $manage = Manage::query()->where('email', $username)->lockForUpdate()->first();
                if (!$manage) {
                    throw new JSONException("该邮箱不存在");
                }
                //verifyPassword 内含旧清洗管线的兼容比对（#833）
                if (!Str::verifyPassword((string)$manage->password, (string)$manage->salt, $password, $rawPassword)) {
                    $failedAudit = [$manage, "登录失败：密码错误"];
                    throw new JSONException("密码错误");
                }

                //谷歌验证器：已绑定则必须校验动态码（密码通过后才校验，避免暴露 2FA 是否开启）
                if (!empty($manage->google_secret)) {
                    if (trim($code) === '') {
                        //未提交动态码：用专属 code 通知前端弹出输入框（密码正确却无码也值得留痕）
                        $failedAudit = [$manage, "登录待验证：密码正确，等待谷歌验证码"];
                        throw new JSONException("该账号已开启两步验证，请输入谷歌验证码", self::CODE_NEED_TOTP);
                    }
                    if (!\App\Util\Totp::verifyAndConsume((string)$manage->google_secret, $code, "manage:" . (int)$manage->id)) {
                        $failedAudit = [$manage, "登录失败：谷歌验证码错误"];
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

                //密码校验通过：旧格式哈希透明升级为 bcrypt。会话 JWT 以密码哈希为签名密钥，
                //哈希一变，其它设备上用旧哈希签发的 cookie 立即失效，故一并吊销，保持设备列表如实。
                if (Str::passwordNeedsUpgrade((string)$manage->password)) {
                    $manage->password = Str::hashPassword($password);
                    $manage->saveOrFail();
                    ManageSessionManager::revokeAll((int)$manage->id);
                }

                $manage->last_login_time = $manage->login_time;
                $manage->last_login_ip = $manage->login_ip;
                $manage->login_time = Date::current();
                $manage->login_ip = Client::getAddress();
                $manage->saveOrFail();

                $expire = $remember ? 86400 * 365 : 86400;
                $expiresAt = time() + $expire;
                $issued = ManageSessionManager::issue($manage, $expiresAt);

                return [
                    'manage' => $manage,
                    'expires_at' => $expiresAt,
                    'cookie' => $issued['cookie'],
                    'session_id' => (int)$issued['session']->id,
                ];
            });
        } catch (JSONException $e) {
            if (is_array($failedAudit)) {
                self::writeFailureAuditLog($failedAudit[0], $failedAudit[1]);
            }
            throw $e;
        }

        return $this->finalizeSession($login);
    }

    /**
     * passkey(WebAuthn) 登入：断言已在控制器校验通过，这里只做与密码登入相同的
     * 状态/班次检查、登录时间更新、会话签发与收尾（记录 + 下发 cookie）。
     *
     * @param Manage $manage 已通过断言校验的管理员
     * @param bool $remember
     * @return array
     * @throws JSONException
     */
    public function issueForManage(Manage $manage, bool $remember = false): array
    {
        $login = DB::transaction(function () use ($manage, $remember): array {
            $m = Manage::query()->where('id', (int)$manage->id)->lockForUpdate()->first();
            if (!$m) {
                throw new JSONException("账号不存在");
            }
            if ($m->status != 1) {
                throw new JSONException("账号已被暂停使用");
            }
            if ($m->type == 2 && Date::isNight()) {
                throw new JSONException("您是白班哦，请注意休息。");
            }
            if ($m->type == 3 && !Date::isNight()) {
                throw new JSONException("您是夜班哦，请注意休息。");
            }

            $m->last_login_time = $m->login_time;
            $m->last_login_ip = $m->login_ip;
            $m->login_time = Date::current();
            $m->login_ip = Client::getAddress();
            $m->saveOrFail();

            $expire = $remember ? 86400 * 365 : 86400;
            $expiresAt = time() + $expire;
            $issued = ManageSessionManager::issue($m, $expiresAt);

            return [
                'manage' => $m,
                'expires_at' => $expiresAt,
                'cookie' => $issued['cookie'],
                'session_id' => (int)$issued['session']->id,
            ];
        });

        return $this->finalizeSession($login, "使用通行密钥登录了后台");
    }

    /**
     * 登入收尾：写审计日志（失败则补偿撤销已签发会话）、下发会话 cookie、触发登录后钩子。
     * 密码登入与 passkey 登入共用。
     *
     * @param array{manage:Manage,expires_at:int,cookie:string,session_id:int} $login
     * @param string $logText 审计日志文案
     * @return array
     * @throws JSONException
     */
    private function finalizeSession(array $login, string $logText = "登录了后台"): array
    {
        // manage_log is MyISAM on existing installations. Writing it inside
        // the InnoDB login transaction violates MySQL GTID consistency after
        // the account/session rows have changed, so audit only after commit.
        try {
            ManageLog::log($login['manage'], $logText);
        } catch (\Throwable) {
            // The cookie has not been sent yet. Revoke the committed session
            // so an audit failure cannot leave a valid, unreachable login.
            try {
                ManageSessionManager::revokeSession(
                    (int)$login['manage']->id,
                    (int)$login['session_id'],
                    false
                );
            } catch (\Throwable) {
                @error_log('后台登录会话补偿撤销失败，管理员ID：' . (int)$login['manage']->id);
            }
            @error_log('后台登录审计日志写入失败，管理员ID：' . (int)$login['manage']->id);
            throw new JSONException('登录日志写入失败，请联系管理员');
        }

        setcookie(ManageConst::SESSION, $login['cookie'], [
            'expires' => $login['expires_at'],
            'path' => '/',
            'httponly' => true,               //禁止 JS 读取会话 Cookie（防 XSS 窃取/日志泄露复用）
            'samesite' => 'Lax',              //防 CSRF：跨站请求不携带后台会话
            'secure' => Client::isSecureRequest(),
        ]);

        //登录成功通知点位（会话已签发；钩子异常不影响登录结果）
        try {
            hook(\App\Consts\Hook::ADMIN_API_AUTH_LOGIN_AFTER, $login['manage']);
        } catch (\Throwable $e) {
        }

        return ["username" => $login['manage']->email, "avatar" => $login['manage']->avatar];
    }

    private static function writeFailureAuditLog(Manage $manage, string $content): void
    {
        try {
            ManageLog::log($manage, $content);
        } catch (\Throwable) {
            // Preserve the intended password/2FA error and retain a server-side
            // diagnostic if the separate failure audit cannot be recorded.
            @error_log('后台登录审计日志写入失败，管理员ID：' . (int)$manage->id);
        }
    }
}
