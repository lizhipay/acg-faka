<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Consts\Hook;
use App\Controller\Base\API\User;
use App\Interceptor\Waf;
use App\Model\Config;
use App\Service\Email;
use App\Service\Sms;
use App\Service\UserSSO;
use App\Service\UserWebauthnService;
use App\Util\Captcha;
use App\Util\Client;
use App\Util\Date;
use App\Util\Str;
use App\Util\Throttle;
use App\Util\Validation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Waf\Filter;

#[Interceptor(Waf::class)]
class Authentication extends User
{

    #[Inject]
    private Email $email;

    #[Inject]
    private Sms $sms;

    #[Inject]
    private UserSSO $sso;

    /**
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     */
    public function register(): array
    {
        #CFG#
        hook(Hook::USER_API_AUTH_REGISTER_BEGIN);
        $registeredState = (int)Config::get("registered_state");
        $registeredType = (int)Config::get("registered_type");
        $registeredEmailVerification = (int)Config::get("registered_email_verification");
        $registeredPhoneVerification = (int)Config::get("registered_phone_verification");
        $registeredVerification = (int)Config::get("registered_verification");
        $usernameLen = (int)Config::get("username_len");

        if ($registeredState == 0) {
            throw new JSONException("注册已关闭");
        }

        if ($registeredVerification == 1 && (!isset($_POST['captcha']) || !Captcha::check((int)$_POST['captcha'], "register"))) {
            throw new JSONException("验证码错误");
        }

        if (!isset($_POST['username']) || !Validation::username((string)$_POST['username'], $usernameLen)) {
            throw new JSONException("用户名最少{$usernameLen}位");
        }
        //user Model
        $user = new \App\Model\User();

        if (\App\Model\User::query()->where("username", $_POST['username'])->first()) {
            throw new JSONException("该用户名已存在，换一个吧");
        }

        $user->username = $_POST['username'];


        if ($registeredType == 2) {
            //email
            if (!isset($_POST['email']) || !Validation::email((string)$_POST['email'])) {
                throw new JSONException("邮箱地址不正确");
            }

            //验证邮箱验证码
            if ($registeredEmailVerification == 1 && !$this->email->checkCaptcha($_POST['email'], Email::CAPTCHA_REGISTER, (int)$_POST['email_captcha'])) {
                throw new JSONException("邮箱验证码不正确");
            }

            if (\App\Model\User::query()->where("email", $_POST['email'])->first()) {
                throw new JSONException("该邮箱已存在，换一个吧");
            }
            $user->email = $_POST['email'];
        } elseif ($registeredType == 1) {
            //phone
            if (!isset($_POST['phone']) || !Validation::phone((string)$_POST['phone'])) {
                throw new JSONException("手机号码不正确");
            }

            //验证手机验证码
            if ($registeredPhoneVerification == 1 && !$this->sms->checkCaptcha($_POST['phone'], Sms::CAPTCHA_REGISTER, (int)$_POST['phone_captcha'])) {
                throw new JSONException("手机验证码不正确");
            }

            if (\App\Model\User::query()->where("phone", $_POST['phone'])->first()) {
                throw new JSONException("该手机已存在，换一个吧");
            }
            $user->phone = $_POST['phone'];
        }

        //验证密码
        if (!isset($_POST['password']) || !Validation::password((string)$_POST['password'])) {
            throw new JSONException("密码最少6位");
        }

        $user->salt = Str::generateRandStr();
        $user->password = Str::hashPassword($_POST['password']);
        $user->app_key = strtoupper(Str::generateRandStr(16));
        $user->create_time = Date::current();
        $user->status = 1;
        $user->avatar = "/favicon.ico";

        //分站上级
        if ($business = \App\Model\Business::get()) {
            $user->pid = $business->user_id;
        } elseif (isset($_COOKIE['promotion_from']) && \App\Util\Promotion::enabled() && \App\Model\User::query()->where("id", $_COOKIE['promotion_from'])->exists()) {
            $user->pid = $_COOKIE['promotion_from'];
        }

        //风控判决。刻意放在下面那个 try 之**外**：try 会把任何异常改写成「注册失败」，
        //风控给的具体理由（含可查证编号）到用户那儿就没了。
        $risk = new \App\Entity\RiskContext('register');
        hook(Hook::USER_API_AUTH_REGISTER_VALIDATED, $risk, $user);

        if ($risk->denied()) {
            throw new JSONException($risk->message("注册失败"));
        }

        //挂人工审核 = 账号照建但不可用，且**不签发会话**。
        //user.status 只有 1 才算可用（UserVisitor / UserSession 都这么判），
        //所以未审核账号自然登录不了，在后台会员列表里就是「禁用」，既有工具全都认得。
        //不跳过 loginSuccess 的话，用户会「注册成功」之后下一次点击就掉线。
        $riskHeld = $risk->held();
        if ($riskHeld) {
            $user->status = 0;
        }

        try {
            //session销毁。注意：原写法 `$x != null ?? destroy()` 中 `??` 右侧永不执行（左侧是 bool，永不为 null），
            //注册验证码用后从未作废，在其 300 秒 TTL 内可被重放——改用显式 if。
            Captcha::destroy("register");
            if ($user->phone != null) {
                $this->sms->destroyCaptcha($user->phone, Sms::CAPTCHA_REGISTER);
            }
            if ($user->email != null) {
                $this->email->destroyCaptcha($user->email, Email::CAPTCHA_REGISTER);
            }
            $user->save();
            if (!$riskHeld) {
                $this->sso->loginSuccess($user);
            }
        } catch (\Exception $e) {
            throw new JSONException("注册失败");
        }


        hook(Hook::USER_API_AUTH_REGISTER_AFTER, $user);
        if ($riskHeld) {
            return $this->json(200, $risk->message("注册成功，账号正在人工审核中，通过后即可登录"));
        }
        return $this->json(200, '注册成功');
    }

    /**
     * @param string $sessionName
     * @param int $type
     * @return array
     */
    private function emailCaptcha(string $sessionName, int $type): array
    {
        $email = (string)($_POST['email'] ?? '');
        $ip = Client::getAddress();
        //发码端限流：不走会话(换 cookie 可绕冷却)，按 IP + 目标邮箱双维拦，防刷邮件
        if (Throttle::tooMany("sendcode:ip:{$ip}", 10, 600)
            || Throttle::tooMany("sendcode:email:" . md5(strtolower(trim($email))), 3, 600)) {
            throw new JSONException("验证码发送过于频繁，请稍后再试");
        }
        $this->email->sendCaptcha($email, $type);
        Captcha::destroy($sessionName);
        return $this->json(200, "验证码发送成功");
    }

    /**
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     */
    public function emailRegisterCaptcha(): array
    {
        if ((int)Config::get("registered_type") != 2) {
            throw new JSONException("该功能暂不可用");
        }

        if (!isset($_POST['captcha']) || !Captcha::check((int)$_POST['captcha'], "emailRegisterCaptcha")) {
            throw new JSONException("验证码错误");
        }

        if (!isset($_POST['email']) || !Validation::email((string)$_POST['email'])) {
            throw new JSONException("邮箱地址不正确");
        }

        if (\App\Model\User::query()->where("email", $_POST['email'])->first()) {
            throw new JSONException("该邮箱已存在，换一个吧");
        }
        return $this->emailCaptcha("emailRegisterCaptcha", Email::CAPTCHA_REGISTER);
    }

    /**
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     */
    public function emailForgetCaptcha(): array
    {
        if ((int)Config::get("forget_type") != 0) {
            throw new JSONException("该功能暂不可用");
        }

        if (!isset($_POST['captcha']) || !Captcha::check((int)$_POST['captcha'], "emailForgetCaptcha")) {
            throw new JSONException("验证码错误");
        }

        if (!isset($_POST['email']) || !Validation::email((string)$_POST['email'])) {
            throw new JSONException("邮箱地址不正确");
        }

        if (!\App\Model\User::query()->where("email", $_POST['email'])->first()) {
            throw new JSONException("该邮箱没有被注册");
        }

        return $this->emailCaptcha("emailForgetCaptcha", Email::CAPTCHA_FORGET);
    }

    /**
     * @param string $sessionName
     * @param int $type
     * @return array
     */
    private function phoneCaptcha(string $sessionName, int $type): array
    {
        $phone = (string)($_POST['phone'] ?? '');
        $ip = Client::getAddress();
        //短信发码更贵：IP + 目标手机双维限流(不走会话)，防刷短信烧钱
        if (Throttle::tooMany("sendcode:ip:{$ip}", 10, 600)
            || Throttle::tooMany("sendcode:phone:" . md5(trim($phone)), 3, 600)) {
            throw new JSONException("验证码发送过于频繁，请稍后再试");
        }
        $this->sms->sendCaptcha($phone, $type);
        Captcha::destroy($sessionName);
        return $this->json(200, "验证码发送成功");
    }

    /**
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     */
    public function phoneRegisterCaptcha(): array
    {
        if ((int)Config::get("registered_type") != 1) {
            throw new JSONException("该功能暂不可用");
        }

        if (!isset($_POST['captcha']) || !Captcha::check((int)$_POST['captcha'], "phoneRegisterCaptcha")) {
            throw new JSONException("验证码错误");
        }

        if (!isset($_POST['phone']) || !Validation::phone((string)$_POST['phone'])) {
            throw new JSONException("手机号码不正确");
        }

        if (\App\Model\User::query()->where("phone", $_POST['phone'])->first()) {
            throw new JSONException("该手机已存在，换一个吧");
        }

        return $this->phoneCaptcha("phoneRegisterCaptcha", Sms::CAPTCHA_REGISTER);
    }

    /**
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     */
    public function phoneForgetCaptcha(): array
    {
        if ((int)Config::get("forget_type") != 1) {
            throw new JSONException("该功能暂不可用");
        }

        if (!isset($_POST['captcha']) || !Captcha::check((int)$_POST['captcha'], "phoneForgetCaptcha")) {
            throw new JSONException("验证码错误");
        }

        if (!isset($_POST['phone']) || !Validation::phone((string)$_POST['phone'])) {
            throw new JSONException("手机号码不正确");
        }

        if (!\App\Model\User::query()->where("phone", $_POST['phone'])->first()) {
            throw new JSONException("该手机没有被注册");
        }

        return $this->phoneCaptcha("phoneForgetCaptcha", Sms::CAPTCHA_FORGET);
    }


    /**
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     */
    public function login(): array
    {
        hook(Hook::USER_API_AUTH_LOGIN_BEGIN);

        //登录爆破/撞库限流：验证码已改为一次性（见 Captcha::check），此处再加频率闸。
        //按来源 IP 计数，挡住单一来源的横向喷洒；成功登录后清零。
        $ip = Client::getAddress();
        if (Throttle::tooMany("login:ip:{$ip}", 30, 300)) {
            throw new JSONException("登录尝试过于频繁，请稍后再试");
        }

        $loginVerification = (int)Config::get("login_verification");

        if ($loginVerification == 1 && (!isset($_POST['captcha']) || !Captcha::check((int)$_POST['captcha'], "login"))) {
            throw new JSONException("验证码错误");
        }

        if (!isset($_POST['username'])) {
            throw new JSONException("用户名输入错误");
        }

        //按「账号+IP」再加一道，挡住盯着某个账号猛试的爆破（跨 IP 分布式仍靠上面的 IP 闸兜底）
        $username = (string)$_POST['username'];
        $userThrottleKey = "login:user:" . md5(strtolower(trim($username))) . ":{$ip}";
        if (Throttle::tooMany($userThrottleKey, 10, 300)) {
            throw new JSONException("登录尝试过于频繁，请稍后再试");
        }

        //验证密码
        if (!isset($_POST['password']) || !Validation::password((string)$_POST['password'])) {
            throw new JSONException("密码错误");
        }


        $user = \App\Model\User::query()->where("username", $_POST['username'])->first()
            ?? \App\Model\User::query()->where("email", $_POST['username'])->first()
            ?? \App\Model\User::query()->where("phone", $_POST['username'])->first();

        if (!$user) {
            $this->loginFail((string)$_POST['username'], "not_found");
            throw new JSONException("用户不存在");
        }

        //verifyPassword 内含旧清洗管线的兼容比对：老账号的特殊字符密码当年哈希的是转义形态（#833）
        if (!Str::verifyPassword((string)$user->password, (string)$user->salt, (string)$_POST['password'], (string)$this->request->unsafePost('password'))) {
            $this->loginFail((string)$_POST['username'], "password", $user);
            throw new JSONException("密码错误");
        }

        if ($user->status == 0) {
            $this->loginFail((string)$_POST['username'], "banned", $user);
            throw new JSONException("您已被封禁");
        }

        //密码校验通过：旧格式哈希透明升级为 bcrypt（下次起用慢哈希，抬高脱库爆破成本）
        if (Str::passwordNeedsUpgrade((string)$user->password)) {
            $user->password = Str::hashPassword((string)$_POST['password']);
            $user->save();
        }

        $remember = (bool)$this->request->post("remember", Filter::BOOLEAN);

        $this->sso->loginSuccess($user, $remember);

        //登录成功，清空该 IP / 账号的失败计数，避免影响后续正常登录
        Throttle::clear("login:ip:{$ip}");
        Throttle::clear($userThrottleKey);

        Captcha::destroy("login");
        return $this->json(200, "登录成功");
    }

    /**
     * 两步验证第二步：密码已在 login() 通过并暂存待验证态，这里校验动态码/恢复码后才签发会话。
     * 独立端点（不重放登录表单）以避开 Turnstile / 图形验证码的单次性。
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     */
    public function totp(): array
    {
        $ip = Client::getAddress();
        if (Throttle::tooMany("totp:ip:{$ip}", 30, 300)) {
            throw new JSONException("验证过于频繁，请稍后再试");
        }

        $pending = \Kernel\Util\Session::get(UserSSO::PENDING_KEY);
        if (!is_array($pending) || empty($pending['uid']) || (int)($pending['exp'] ?? 0) <= time()) {
            \Kernel\Util\Session::remove(UserSSO::PENDING_KEY);
            throw new JSONException("登录状态已失效，请重新登录", UserSSO::CODE_NEED_TOTP);
        }

        $uid = (int)$pending['uid'];
        if (Throttle::tooMany("totp:uid:{$uid}", 10, 300)) {
            throw new JSONException("验证过于频繁，请稍后再试");
        }

        $user = \App\Model\User::query()->find($uid);
        if (!$user || $user->status != 1 || empty($user->totp_secret)) {
            \Kernel\Util\Session::remove(UserSSO::PENDING_KEY);
            throw new JSONException("登录状态已失效，请重新登录", UserSSO::CODE_NEED_TOTP);
        }

        $code = trim((string)($_POST['code'] ?? ''));
        if ($code === '') {
            throw new JSONException("请输入验证码");
        }

        //登录动态码一次性消费：防止被嗅探到的码在 90 秒窗口内被重放登录
        $ok = \App\Util\Totp::verifyAndConsume((string)$user->totp_secret, $code, "user:" . $uid);
        if (!$ok) {
            //动态码不对时再试恢复码：命中即消费掉该恢复码
            $before = (string)$user->totp_recovery;
            $left = \App\Util\RecoveryCode::consume($before, $code);
            if ($left !== null) {
                //原子消费：仅当该列仍是消费前的值时才写入，命中 1 行才算成功。
                //否则并发的两个请求会各自读到同一份恢复码、都校验通过，一条恢复码换到两个会话。
                $affected = \App\Model\User::query()
                    ->where('id', $uid)
                    ->where('totp_recovery', $before)
                    ->update(['totp_recovery' => $left]);
                if ($affected === 1) {
                    $user->totp_recovery = $left;
                    $ok = true;
                }
            }
        }

        if (!$ok) {
            $this->loginFail((string)$user->username, "totp", $user);
            throw new JSONException("验证码错误");
        }

        $remember = (bool)($pending['remember'] ?? false);
        \Kernel\Util\Session::remove(UserSSO::PENDING_KEY);
        $this->sso->issue($user, $remember, (string)($pending['via'] ?? ''));

        Throttle::clear("totp:ip:{$ip}");
        Throttle::clear("totp:uid:{$uid}");
        return $this->json(200, "登录成功");
    }

    /**
     * 通行密钥登录选项（usernameless：不指定账号，由浏览器列出本站已保存的通行密钥）。
     * @return array
     */
    public function passkeyOptions(): array
    {
        return $this->json(200, "success", UserWebauthnService::loginOptions());
    }

    /**
     * 通行密钥登录：校验断言 → 找到会员 → 交给 loginSuccess。认证器做了用户验证（指纹/面容/PIN）
     * 即视为已满足多因素、免两步验证；没做用户验证而账号开了两步验证，照常抛 CODE_NEED_TOTP 弹动态码。
     * 不走图形验证码与 LOGIN_BEGIN（通行密钥本身无法被脚本批量尝试）。
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     */
    public function passkeyLogin(): array
    {
        $ip = Client::getAddress();
        if (Throttle::tooMany("passkey:ip:{$ip}", 30, 300)) {
            throw new JSONException("登录尝试过于频繁，请稍后再试");
        }

        $rawId = (string)$this->request->post("id");
        $authData = (string)$this->request->post("authenticatorData");
        $clientData = (string)$this->request->post("clientDataJSON");
        $signature = (string)$this->request->post("signature");
        $userHandle = (string)$this->request->post("userHandle");
        foreach ([$rawId, $authData, $clientData, $signature] as $part) {
            if (!preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $part)) {
                throw new JSONException("通行密钥数据不完整，请重试");
            }
        }
        if ($userHandle !== '' && !preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $userHandle)) {
            $userHandle = '';
        }

        $result = UserWebauthnService::login($rawId, $authData, $clientData, $signature, $userHandle);
        $user = $result['user'];
        if ($user->status != 1) {
            $this->loginFail((string)$user->username, "banned", $user);
            throw new JSONException("您已被封禁");
        }

        $remember = (bool)$this->request->post("remember", Filter::BOOLEAN);
        $this->sso->loginSuccess($user, $remember, $result['userVerified'], 'passkey');

        Throttle::clear("passkey:ip:{$ip}");
        return $this->json(200, "登录成功");
    }

    /**
     * 登录失败通知点位（钩子异常不影响原有失败流程）
     * @param string $account
     * @param string $reason not_found|password|banned
     */
    private function loginFail(string $account, string $reason, ?\App\Model\User $user = null): void
    {
        try {
            hook(Hook::USER_API_AUTH_LOGIN_FAIL, $account, $reason);
        } catch (\Throwable $e) {
        }
        //安全日志：仅在命中真实会员时记（账号不存在的失败不归属任何会员）。失败一律标记为需关注。
        if ($user) {
            $labels = ['password' => '登录失败：密码错误', 'banned' => '登录失败：账号已被封禁', 'totp' => '登录失败：两步验证码错误'];
            \App\Model\UserLog::write($user, 'login_fail', $labels[$reason] ?? ('登录失败：' . $reason), 1);
        }
    }

    /**
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     */
    public function password(): array
    {
        $forgetType = (int)Config::get("forget_type");

        //风控判决。刻意放在验证码校验**之前** —— 拒绝时不该白白消耗掉
        //用户手里那条邮件/短信验证码，也不该替攻击者把站长的短信费烧掉。
        $riskAccount = (string)($_POST['username'] ?? '');
        $risk = new \App\Entity\RiskContext('password');
        hook(Hook::USER_API_AUTH_PASSWORD_BEGIN, $risk, $riskAccount);
        if ($risk->denied() || $risk->held()) {
            throw new JSONException($risk->message("操作过于频繁，请稍后再试"));
        }

        //找回密码是账号接管的高价值面：验证码校验侧原本无任何尝试限制，6 位码可在 300s 窗口内无限爆破。
        //风控插件（可被停用）不可依赖，这里加硬性双维度限流：IP 维度挡单点喷洒；目标维度**不含 IP**，
        //杜绝分布式 IP 绕过。触限即销毁该目标的找回验证码，使已发出的码立即失效，攻击者必须重新发码
        //（发码侧有 60s 冷却 + 图形验证码）。（F-32）
        $ip = Client::getAddress();
        $forgetTargetKey = "forget:target:" . md5(strtolower(trim($riskAccount)));
        if (Throttle::tooMany("forget:ip:{$ip}", 30, 300) || Throttle::tooMany($forgetTargetKey, 10, 600)) {
            $forgetType == 0
                ? $this->email->destroyCaptcha($riskAccount, Email::CAPTCHA_FORGET)
                : $this->sms->destroyCaptcha($riskAccount, Sms::CAPTCHA_FORGET);
            throw new JSONException("尝试过于频繁，请稍后再试");
        }

        if (!isset($_POST['password']) || !Validation::password((string)$_POST['password'])) {
            throw new JSONException("密码最少6位");
        }

        if ($forgetType == 0) {
            if (!isset($_POST['username']) || !Validation::email((string)$_POST['username'])) {
                throw new JSONException("邮箱地址不正确");
            }
            if (!$this->email->checkCaptcha($_POST['username'], Email::CAPTCHA_FORGET, (int)$_POST['captcha'])) {
                throw new JSONException("邮箱验证码不正确");
            }
            $user = \App\Model\User::query()->where("email", $_POST['username'])->first();
            $this->email->destroyCaptcha($_POST['username'], Email::CAPTCHA_FORGET);
        } else {
            if (!isset($_POST['username']) || !Validation::phone((string)$_POST['username'])) {
                throw new JSONException("手机号不正确");
            }

            if (!$this->sms->checkCaptcha($_POST['username'], Sms::CAPTCHA_FORGET, (int)$_POST['captcha'])) {
                throw new JSONException("手机验证码不正确");
            }
            $user = \App\Model\User::query()->where("phone", $_POST['username'])->first();
            $this->sms->destroyCaptcha($_POST['username'], Sms::CAPTCHA_FORGET);
        }

        //账号在「发码后、提交前」被删会让 $user 为 null（低危 500 面），给出通用错误而非崩溃。
        if (!$user) {
            throw new JSONException("账号异常，请重新发起找回");
        }

        $user->password = Str::hashPassword($_POST['password']);
        $user->save();
        //改密后吊销该账号所有在线会话，被盗号者手里的旧 cookie 立即失效
        \App\Service\UserSessionManager::revokeAll((int)$user->id);

        //成功即清零两个维度的失败计数，避免误伤本人后续操作。
        Throttle::clear("forget:ip:{$ip}");
        Throttle::clear($forgetTargetKey);

        return $this->json(200, "密码重置成功");
    }
}