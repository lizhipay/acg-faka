<?php
declare(strict_types=1);

namespace App\Service\Impl;


use App\Model\Config as CFG;
use App\Service\Email;
use App\Util\Date;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;
use PHPMailer\PHPMailer\PHPMailer;

class EmailService implements Email
{
    #[Inject]
    private PHPMailer $mailer;

    /**
     * @param string $email
     * @param string $title
     * @param string $content
     * @return bool
     */
    public function send(string $email, string $title, string $content): bool
    {
        try {
            $config = json_decode(\App\Model\Config::get("email_config"), true);
            $shopName = CFG::get("shop_name");
            $secure = (int)$config['secure'] == 0 ? 'ssl' : 'tls';
            $mail = $this->mailer;
            $mail->CharSet = 'UTF-8';
            $mail->IsSMTP();
            $mail->SMTPDebug = 0;
            $mail->SMTPAuth = true;
            $mail->SMTPSecure = $secure;  //tls/ssl
            $mail->Host = $config['smtp'];
            $mail->Port = $config['port'];
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->SetFrom($config['username'], $shopName);
            $mail->AddAddress($email);
            $mail->Subject = $title;
            $mail->MsgHTML($content);
            $mail->Timeout = 10; //默认超时10秒钟
            $result = $mail->Send();
        } catch (\Exception $e) {
            return false;
        }

        if (!$result) {
            return false;
        }

        return true;
    }

    /**
     * @param string $email
     * @param int $type
     * @return void
     * @throws \Kernel\Exception\JSONException
     */
    public function sendCaptcha(string $email, int $type): void
    {
        $capthca = mt_rand(100000, 999999);
        $key = match ($type) {
            Email::CAPTCHA_REGISTER => sprintf(\App\Consts\Email::CAPTCHA_REGISTER, $email),
            Email::CAPTCHA_FORGET => sprintf(\App\Consts\Email::CAPTCHA_FORGET, $email),
            Email::CAPTCHA_BIND_NEW => sprintf(\App\Consts\Email::CAPTCHA_BIND_NEW, $email),
            Email::CAPTCHA_BIND_OLD => sprintf(\App\Consts\Email::CAPTCHA_BIND_OLD, $email),
        };

        if (isset($_SESSION[$key])) {
            if ($_SESSION[$key]['time'] + 60 > time()) {
                throw new JSONException("验证码发送频繁，请稍后再试");
            }
        }

        if ($type == Email::CAPTCHA_REGISTER) {
            if (!$this->send($email, "【注册账号】验证您的电子邮件", "您好，您正在进行账号注册，本次验证码为：{$capthca}，有效期为5分钟。")) {
                throw new JSONException("验证码发送失败，请稍后再试");
            }
        } else if ($type == Email::CAPTCHA_FORGET) {
            if (!$this->send($email, "【找回密码】验证您的电子邮件", "您好，您正在找回密码，本次验证码为：{$capthca}，有效期为5分钟。")) {
                throw new JSONException("验证码发送失败，请稍后再试");
            }
        } else if ($type == Email::CAPTCHA_BIND_NEW) {
            if (!$this->send($email, "【绑定新邮箱】验证您的电子邮件", "您好，您正在绑定新邮箱，本次验证码为：{$capthca}，有效期为5分钟。")) {
                throw new JSONException("验证码发送失败，请稍后再试");
            }
        } else if ($type == Email::CAPTCHA_BIND_OLD) {
            if (!$this->send($email, "【修改邮箱】验证您的电子邮件", "您好，您的邮箱正在被修改，本次验证码为：{$capthca}，有效期为5分钟。")) {
                throw new JSONException("验证码发送失败，请稍后再试");
            }
        }

        $_SESSION[$key] = ["time" => time(), "code" => $capthca];
    }


    /**
     * @param string $email
     * @param int $type
     * @param int $code
     * @return bool
     */
    public function checkCaptcha(string $email, int $type, int $code): bool
    {
        $key = match ($type) {
            Email::CAPTCHA_REGISTER => sprintf(\App\Consts\Email::CAPTCHA_REGISTER, $email),
            Email::CAPTCHA_FORGET => sprintf(\App\Consts\Email::CAPTCHA_FORGET, $email),
            Email::CAPTCHA_BIND_NEW => sprintf(\App\Consts\Email::CAPTCHA_BIND_NEW, $email),
            Email::CAPTCHA_BIND_OLD => sprintf(\App\Consts\Email::CAPTCHA_BIND_OLD, $email),
        };

        if (!isset($_SESSION[$key])) {
            return false;
        }

        if ($_SESSION[$key]['code'] != $code) {
            return false;
        }

        if ($_SESSION[$key]['time'] + 300 < time()) {
            return false;
        }

        return true;
    }

    /**
     * @param string $email
     * @param int $type
     */
    public function destroyCaptcha(string $email, int $type): void
    {
        $key = match ($type) {
            Email::CAPTCHA_REGISTER => sprintf(\App\Consts\Email::CAPTCHA_REGISTER, $email),
            Email::CAPTCHA_FORGET => sprintf(\App\Consts\Email::CAPTCHA_FORGET, $email),
            Email::CAPTCHA_BIND_NEW => sprintf(\App\Consts\Email::CAPTCHA_BIND_NEW, $email),
            Email::CAPTCHA_BIND_OLD => sprintf(\App\Consts\Email::CAPTCHA_BIND_OLD, $email),
        };
        unset($_SESSION[$key]);
    }
}