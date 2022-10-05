<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Model\Config;
use App\Service\Email;
use App\Service\Sms;
use App\Util\Captcha;
use App\Util\QrCode;
use App\Util\Str;
use App\Util\Validation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class Security extends User
{
    #[Inject]
    private Email $email;

    #[Inject]
    private Sms $sms;

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function personal(): array
    {
        $user = $this->getUser();
        $user->avatar = (string)$_POST['avatar'];
        $user->qq = (string)$_POST['qq'];
        $user->alipay = (string)$_POST['alipay'];
        $user->nicename = (string)$_POST['nicename'];
        $user->settlement = (int)$_POST['settlement'] == 0 ? 0 : 1;

        $plugin = (array)$_POST['plugin'];

        $fields = [
            'username',
            'email',
            'phone',
            'qq',
            'password',
            'salt',
            'app_key',
            'avatar',
            'balance',
            'coin',
            'integral',
            'create_time',
            'login_time',
            'last_login_time',
            'login_ip',
            'last_login_ip',
            'pid',
            'recharge',
            'total_coin',
            'status',
            'business_level',
            'nicename',
            'alipay',
            'wechat',
            'settlement',
            'id'
        ];

        foreach ($fields as $value){
            unset($plugin[$value]);
        }

        foreach ($plugin as $key => $val) {
            if (in_array(strtolower($key) , $fields)){
                throw new JSONException("are you an idiot?");
            }
            $user->$key = $val;
        }

        $wechat = (string)$_POST['wechat'];
        if ($wechat != "") {

            $qrCode = QrCode::parse(BASE_PATH . $wechat);

            if ($qrCode == "") {
                throw new JSONException("您上传的微信二维码错误。");
            }

            $user->wechat = $qrCode;
        }

        $user->save();
        return $this->json(200, "修改成功");
    }

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function email(): array
    {
        if (!$this->email->checkCaptcha($_POST['email'], Email::CAPTCHA_BIND_NEW, (int)$_POST['email_captcha'])) {
            throw new JSONException("邮箱验证码不正确");
        }
        $user = $this->getUser();
        $user->email = $_POST['email'];
        $user->save();

        $this->email->destroyCaptcha($user->email, Email::CAPTCHA_BIND_NEW);
        return $this->json(200, "修改成功");
    }

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function phone(): array
    {
        if (!$this->sms->checkCaptcha($_POST['phone'], Sms::CAPTCHA_BIND_NEW, (int)$_POST['phone_captcha'])) {
            throw new JSONException("手机验证码不正确");
        }
        $user = $this->getUser();
        $user->phone = $_POST['phone'];
        $user->save();

        $this->sms->destroyCaptcha($user->phone, Sms::CAPTCHA_BIND_NEW);
        return $this->json(200, "修改成功");
    }

    /**
     * @throws \Kernel\Exception\JSONException
     */
    public function password(): array
    {
        $oldPassword = (string)$_POST['old_password'];
        $password = (string)$_POST['password'];
        $rePassword = (string)$_POST['re_password'];
        $user = $this->getUser();
        if (Str::generatePassword($oldPassword, $user->salt) != $user->password) {
            throw new JSONException("旧密码输入不正确");
        }
        if ($password != $rePassword) {
            throw new JSONException("两次密码输入不一致");
        }

        if (!Validation::password($password)) {
            throw new JSONException("新密码格式不正确，密码必须6位以上");
        }

        $user->password = Str::generatePassword($password, $user->salt);
        $user->save();
        return $this->json(200, "修改成功");
    }

    /**
     * @throws \Kernel\Exception\JSONException
     */
    public function emailBindNew(): array
    {
        if (!isset($_POST['captcha']) || !Captcha::check((int)$_POST['captcha'], "emailBindNew")) {
            throw new JSONException("验证码错误");
        }

        if (!isset($_POST['email']) || !Validation::email((string)$_POST['email'])) {
            throw new JSONException("邮箱地址不正确");
        }

        if (\App\Model\User::query()->where("email", $_POST['email'])->first()) {
            throw new JSONException("该邮箱已被他人绑定");
        }
        $this->email->sendCaptcha((string)$_POST['email'], Email::CAPTCHA_BIND_NEW);
        Captcha::destroy("emailBindNew");
        return $this->json(200, "验证码发送成功");
    }

    /**
     * @throws \Kernel\Exception\JSONException
     */
    public function phoneBindNew(): array
    {
        if (!isset($_POST['captcha']) || !Captcha::check((int)$_POST['captcha'], "phoneBindNew")) {
            throw new JSONException("验证码错误");
        }

        if (!isset($_POST['phone']) || !Validation::phone((string)$_POST['phone'])) {
            throw new JSONException("手机号码不正确");
        }

        if (\App\Model\User::query()->where("phone", $_POST['phone'])->first()) {
            throw new JSONException("该手机已被他人绑定");
        }

        $this->sms->sendCaptcha((string)$_POST['phone'], Sms::CAPTCHA_BIND_NEW);
        Captcha::destroy("phoneBindNew");
        return $this->json(200, "验证码发送成功");
    }


    /**
     * @return array
     */
    public function resetKey(): array
    {
        $user = \App\Model\User::query()->find($this->getUser()->id);
        $user->app_key = strtoupper(Str::generateRandStr(16));;
        $user->save();
        return $this->json(200, "重置成功", ["app_key" => $user->app_key]);
    }


}