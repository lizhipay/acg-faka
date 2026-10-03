<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Consts\Manage as ManageConst;
use App\Controller\Base\API\Manage;
use App\Model\ManageLog;
use App\Service\ManageSessionManager;
use App\Service\ManageSSO;
use App\Service\ManageWebauthnService;
use App\Util\AdminLock;
use App\Util\Captcha;
use App\Util\Client;
use App\Util\Str;
use App\Util\Throttle;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Post;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

/**
 * Class Auth
 * @package App\Controller\Admin\Api
 */
class Authentication extends Manage
{
    /** 通行密钥登入待补谷歌验证码的暂存态（UV=0 金钥登入已开 2FA 的账号时） */
    private const PASSKEY_PENDING = 'ADMIN_PASSKEY_PENDING';

    #[Inject]
    private ManageSSO $sso;

    /**
     * @param string $username
     * @param string $password
     * @return array
     */
    public function login(string $username, string $password): array
    {
        $ip = Client::getAddress();
        //后台登录限流：挡住账号/密码/验证码爆破（本次入侵实测被刷 27 次）
        if (Throttle::tooMany("adminlogin:{$ip}", 10, 600)) {
            $this->loginFail($username, "throttled");
            throw new JSONException("登录尝试过于频繁，请稍后再试");
        }
        //图形验证码：无论对错校验后即作废，单次有效（防机器人爆破）
        //网站设置-其他验证码可关闭（未显式关闭时默认开启，保证老站升级后行为不变）
        if ((string)\App\Model\Config::get("admin_login_verification") !== '0') {
            $captchaOk = Captcha::check((int)$this->request->post("captcha"), "adminLogin");
            Captcha::destroy("adminLogin");
            if (!$captchaOk) {
                $this->loginFail($username, "captcha");
                throw new JSONException("验证码错误");
            }
        }
        $remember = (bool)$this->request->post("remember", Filter::BOOLEAN);
        $code = (string)$this->request->post("code");
        try {
            $result = $this->sso->login($username, $password, $remember, $code, (string)$this->request->unsafePost('password'));
        } catch (JSONException $e) {
            //待输入两步验证码不算失败（密码已正确）
            if ($e->getCode() !== \App\Service\Bind\ManageSSO::CODE_NEED_TOTP) {
                $this->loginFail($username, self::failReason($e->getMessage()));
            }
            throw $e;
        }
        Throttle::clear("adminlogin:{$ip}"); //登录成功后清零
        return $this->json(200, "success", $result);
    }

    /**
     * 后台登录失败通知点位（钩子异常不影响原有失败流程）
     * @param string $email
     * @param string $reason
     */
    private function loginFail(string $email, string $reason): void
    {
        try {
            hook(\App\Consts\Hook::ADMIN_API_AUTH_LOGIN_FAIL, $email, $reason);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 把 SSO 抛出的失败文案归一成稳定的原因码，供插件按类别统计
     * @param string $message
     * @return string
     */
    private static function failReason(string $message): string
    {
        return match (true) {
            str_contains($message, "不存在") => "not_found",
            str_contains($message, "谷歌验证码") => "totp",
            str_contains($message, "密码错误") => "password",
            str_contains($message, "暂停") => "banned",
            str_contains($message, "白班") || str_contains($message, "夜班") => "shift",
            default => "other",
        };
    }

    /* ============================ passkey 登入 ============================ */

    /**
     * passkey 登入选项（usernameless，认证器自行选择已保存的凭证）。
     */
    public function passkeyOptions(): array
    {
        return $this->json(200, "success", ManageWebauthnService::loginOptions());
    }

    /**
     * passkey 登入：校验断言 → 找到对应管理员 → 签发会话。
     */
    public function passkeyLogin(): array
    {
        $ip = Client::getAddress();
        if (Throttle::tooMany("adminpasskey:{$ip}", 15, 600)) {
            throw new JSONException("尝试过于频繁，请稍后再试");
        }
        [$rawId, $authData, $clientData, $signature] = $this->passkeyPayload();
        $remember = (bool)$this->request->post("remember", Filter::BOOLEAN);

        ['manage' => $manage, 'userVerified' => $userVerified] = ManageWebauthnService::login($rawId, $authData, $clientData, $signature);

        // 对齐会员端：做了设备验证（UV：指纹/面容/PIN）的通行密钥本身即双因素，可直接登入；
        // 未做 UV（纯持有型安全金钥）且账号开启了谷歌两步验证时，仍需补一次动态码——否则管理员
        // 主动开启的第二因素会被单因素金钥绕过。此处暂存待验证态，前端据 42001 弹码后调 passkeyTotp。
        if (!$userVerified && !empty($manage->google_secret)) {
            \Kernel\Util\Session::set(self::PASSKEY_PENDING, [
                'mid' => (int)$manage->id,
                'remember' => $remember,
                'exp' => time() + 300,
            ]);
            throw new JSONException("该账号已开启两步验证，请输入谷歌验证码", \App\Service\Bind\ManageSSO::CODE_NEED_TOTP);
        }

        \Kernel\Util\Session::remove(self::PASSKEY_PENDING);
        $result = $this->sso->issueForManage($manage, $remember);
        Throttle::clear("adminpasskey:{$ip}");
        return $this->json(200, "success", $result);
    }

    /**
     * 通行密钥登入的两步验证补码：UV=0 的金钥登入已开谷歌 2FA 的账号时，
     * 前端拿到 42001 后把动态码送到这里完成登入（暂存态在 passkeyLogin 里设置，单次、5 分钟有效）。
     * @throws JSONException
     */
    public function passkeyTotp(): array
    {
        $pending = \Kernel\Util\Session::get(self::PASSKEY_PENDING);
        if (!is_array($pending) || empty($pending['mid']) || (int)($pending['exp'] ?? 0) <= time()) {
            \Kernel\Util\Session::remove(self::PASSKEY_PENDING);
            throw new JSONException("登录状态已失效，请重新登录", \App\Service\Bind\ManageSSO::CODE_NEED_TOTP);
        }
        $mid = (int)$pending['mid'];
        if (Throttle::tooMany("adminpasskeytotp:{$mid}", 10, 600)) {
            throw new JSONException("尝试过于频繁，请稍后再试");
        }
        $manage = \App\Model\Manage::query()->find($mid);
        if (!$manage || empty($manage->google_secret)) {
            \Kernel\Util\Session::remove(self::PASSKEY_PENDING);
            throw new JSONException("登录状态已失效，请重新登录", \App\Service\Bind\ManageSSO::CODE_NEED_TOTP);
        }
        $code = trim((string)$this->request->post("code"));
        if ($code === '' || !\App\Util\Totp::verifyAndConsume((string)$manage->google_secret, $code, "manage:" . $mid)) {
            throw new JSONException("谷歌验证码错误");
        }
        \Kernel\Util\Session::remove(self::PASSKEY_PENDING);
        $result = $this->sso->issueForManage($manage, (bool)($pending['remember'] ?? false));
        Throttle::clear("adminpasskeytotp:{$mid}");
        return $this->json(200, "success", $result);
    }

    /* ============================ 闲置锁屏 解锁 / 续命 ============================ */

    /**
     * 交互续命：由前端在真实用户交互时节流调用，仅在未锁定时推进活动时间。
     * 回传 data.locked 告知前端是否已进入锁定。
     */
    public function ping(): array
    {
        $resolved = $this->lockedSession();
        $active = AdminLock::markActive($resolved['session']);
        return $this->json(200, "success", ["locked" => !$active]);
    }

    /**
     * 只读锁定状态查询：不推进活动时间。供前端闲置时判断是否该弹锁屏——
     * 多标签页时，空闲的后台页用它确认服务器是否真的已锁，避免在其它标签页仍活跃、
     * 会话未锁时误跳锁屏整页重载丢失未存内容；也不会像 ping 那样让空闲页把会话续命。
     */
    public function lockStatus(): array
    {
        $resolved = $this->lockedSession();
        return $this->json(200, "success", ["locked" => AdminLock::isLocked($resolved['session'])]);
    }

    /**
     * 密码解锁：会话在登入时已通过 2FA，锁屏解锁仅需密码（或 passkey）。
     */
    public function unlockPassword(): array
    {
        $resolved = $this->lockedSession();
        $manage = $resolved['manage'];
        if (Throttle::tooMany("adminunlock:{$manage->id}", 10, 600)) {
            throw new JSONException("尝试过于频繁，请稍后再试");
        }
        // 密码只从 POST 读取（不走方法参数=$_REQUEST），避免经 GET query string 落入日志/浏览历史/Referer。
        $password = (string)$this->request->post('password');
        if (!Str::verifyPassword((string)$manage->password, (string)$manage->salt, $password, (string)$this->request->unsafePost('password'))) {
            try {
                ManageLog::log($manage, "锁屏解锁失败：密码错误");
            } catch (\Throwable) {
            }
            throw new JSONException("密码错误");
        }
        AdminLock::refresh($resolved['session']);
        Throttle::clear("adminunlock:{$manage->id}");
        return $this->json(200, "success");
    }

    /**
     * passkey 解锁选项（限定当前账号已注册的凭证）。
     */
    public function unlockPasskeyOptions(): array
    {
        $resolved = $this->lockedSession();
        return $this->json(200, "success", ManageWebauthnService::unlockOptions($resolved['manage']));
    }

    /**
     * passkey 解锁。
     */
    public function unlockPasskey(): array
    {
        $resolved = $this->lockedSession();
        $manage = $resolved['manage'];
        if (Throttle::tooMany("adminunlock:{$manage->id}", 10, 600)) {
            throw new JSONException("尝试过于频繁，请稍后再试");
        }
        [$rawId, $authData, $clientData, $signature] = $this->passkeyPayload();
        ManageWebauthnService::unlockVerify($manage, $rawId, $authData, $clientData, $signature);
        AdminLock::refresh($resolved['session']);
        Throttle::clear("adminunlock:{$manage->id}");
        return $this->json(200, "success");
    }

    /**
     * 收集并校验 passkey 断言四要素。
     * @return array{0:string,1:string,2:string,3:string}
     * @throws JSONException
     */
    private function passkeyPayload(): array
    {
        $rawId = (string)$this->request->post("id");
        $authData = (string)$this->request->post("authenticatorData");
        $clientData = (string)$this->request->post("clientDataJSON");
        $signature = (string)$this->request->post("signature");
        if ($rawId === '' || $authData === '' || $clientData === '' || $signature === '') {
            throw new JSONException("通行密钥数据不完整");
        }
        return [$rawId, $authData, $clientData, $signature];
    }

    /**
     * 从 cookie 解析当前（可能已锁定的）后台会话。本控制器不受 ManageSession 拦截器守卫，
     * 故锁定期间这些解锁/续命端点仍可访问；此处手动校验会话身份。
     *
     * @return array{manage:\App\Model\Manage, session:\App\Model\ManageSession}
     * @throws JSONException
     */
    private function lockedSession(): array
    {
        $cookie = (string)($_COOKIE[ManageConst::SESSION] ?? '');
        if ($cookie === '') {
            throw new JSONException("会话不存在，请重新登录", 0);
        }
        $resolved = ManageSessionManager::authenticate($cookie, false);
        if (!$resolved) {
            throw new JSONException("会话已失效，请重新登录", 0);
        }
        return $resolved;
    }
}