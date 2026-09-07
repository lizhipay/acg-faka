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
use App\Util\Captcha;
use App\Util\Date;
use App\Util\Str;
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
        $user->password = Str::generatePassword($_POST['password'], $user->salt);
        $user->app_key = strtoupper(Str::generateRandStr(16));
        $user->create_time = Date::current();
        $user->status = 1;
        $user->avatar = "/favicon.ico";

        //分站上级
        if ($business = \App\Model\Business::get()) {
            $user->pid = $business->user_id;
        } elseif (isset($_COOKIE['promotion_from']) && \App\Model\User::query()->where("id", $_COOKIE['promotion_from'])->exists()) {
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
            //session销毁
            Captcha::destroy("register");
                $user->phone != null ?? $this->sms->destroyCaptcha($user->phone, Sms::CAPTCHA_REGISTER);
                $user->email != null ?? $this->email->destroyCaptcha($user->email, Email::CAPTCHA_REGISTER);
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
        $this->email->sendCaptcha((string)$_POST['email'], $type);
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
        $this->sms->sendCaptcha((string)$_POST['phone'], $type);
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

        $loginVerification = (int)Config::get("login_verification");

        if ($loginVerification == 1 && (!isset($_POST['captcha']) || !Captcha::check((int)$_POST['captcha'], "login"))) {
            throw new JSONException("验证码错误");
        }

        if (!isset($_POST['username'])) {
            throw new JSONException("用户名输入错误");
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
            $this->loginFail((string)$_POST['username'], "password");
            throw new JSONException("密码错误");
        }

        if ($user->status == 0) {
            $this->loginFail((string)$_POST['username'], "banned");
            throw new JSONException("您已被封禁");
        }

        $remember = (bool)$this->request->post("remember", Filter::BOOLEAN);

        $this->sso->loginSuccess($user, $remember);

        Captcha::destroy("login");
        return $this->json(200, "登录成功");
    }

    /**
     * 登录失败通知点位（钩子异常不影响原有失败流程）
     * @param string $account
     * @param string $reason not_found|password|banned
     */
    private function loginFail(string $account, string $reason): void
    {
        try {
            hook(Hook::USER_API_AUTH_LOGIN_FAIL, $account, $reason);
        } catch (\Throwable $e) {
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

        $user->password = Str::generatePassword($_POST['password'], $user->salt);
        $user->save();

        return $this->json(200, "密码重置成功");
    }
}