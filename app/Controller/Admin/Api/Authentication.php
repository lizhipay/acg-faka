<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Service\ManageSSO;
use App\Util\Captcha;
use App\Util\Client;
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
            $result = $this->sso->login($username, $password, $remember, $code);
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
}