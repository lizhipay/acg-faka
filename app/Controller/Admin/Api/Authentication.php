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
            throw new JSONException("登录尝试过于频繁，请稍后再试");
        }
        //图形验证码：无论对错校验后即作废，单次有效（防机器人爆破）
        $captchaOk = Captcha::check((int)$this->request->post("captcha"), "adminLogin");
        Captcha::destroy("adminLogin");
        if (!$captchaOk) {
            throw new JSONException("验证码错误");
        }
        $remember = (bool)$this->request->post("remember", Filter::BOOLEAN);
        $code = (string)$this->request->post("code");
        $result = $this->sso->login($username, $password, $remember, $code);
        Throttle::clear("adminlogin:{$ip}"); //登录成功后清零
        return $this->json(200, "success", $result);
    }
}