<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Interceptor\Waf;
use App\Util\Client;
use App\Util\Throttle;
use Kernel\Annotation\Get;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(Waf::class)]
class Captcha
{

    /**
     * @param string $action
     * @throws JSONException
     */
    public function image(#[Get] string $action): void
    {
        //验证码生成走 GD 绘制，本无鉴权/限流。给一道宽松的按 IP 频率闸，挡住脚本化刷图的 CPU 消耗，
        //正常用户(每次开页取 1~2 张)远达不到阈值。
        if (Throttle::tooMany("captcha:ip:" . Client::getAddress(), 100, 60)) {
            throw new JSONException("请求过于频繁，请稍后再试");
        }
        \App\Util\Captcha::generate($action);
    }
}
