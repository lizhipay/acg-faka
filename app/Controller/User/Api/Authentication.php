<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


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
     * @throws \Kernel\Exception\JSONException
     */
    public function register(): array
    {
        #CFG#
        hook(\App\Consts\Hook::USER_API_AUTH_REGISTER_BEGIN);
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
        if ((int)$_POST['pid'] != 0 && \App\Model\User::query()->find((int)$_POST['pid'])) {
            $user->pid = $_POST['pid'];
        }

        try {
            //session销毁
            Captcha::destroy("register");
            $user->phone != null ?? $this->sms->destroyCaptcha($user->phone, Sms::CAPTCHA_REGISTER);
            $user->email != null ?? $this->email->destroyCaptcha($user->email, Email::CAPTCHA_REGISTER);
            $user->save();
            $this->sso->loginSuccess($user);
        } catch (\Exception $e) {
            throw new JSONException("注册失败");
        }


        hook(\App\Consts\Hook::USER_API_AUTH_REGISTER_AFTER, $user);
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
     * @throws \Kernel\Exception\JSONException
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
     * @throws \Kernel\Exception\JSONException
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
     * @throws \Kernel\Exception\JSONException
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
     * @throws \Kernel\Exception\JSONException
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
     * @throws \Kernel\Exception\JSONException
     */
    public function login(): array
    {
        hook(\App\Consts\Hook::USER_API_AUTH_LOGIN_BEGIN);

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
            throw new JSONException("用户不存在");
        }

        if (Str::generatePassword($_POST['password'], $user->salt) != $user->password) {
            throw new JSONException("密码错误");
        }

        if ($user->status == 0) {
            throw new JSONException("您已被封禁");
        }

        $this->sso->loginSuccess($user);

        Captcha::destroy("login");
        return $this->json(200, "登录成功");
    }

    /**
     * @throws \Kernel\Exception\JSONException
     */
    public function password(): array
    {
        $forgetType = (int)Config::get("forget_type");

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