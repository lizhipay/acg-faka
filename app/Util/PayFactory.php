<?php
declare(strict_types=1);

namespace App\Util;


use App\Model\Pay as PayModel;
use App\Pay\Base;
use Kernel\Exception\JSONException;

/**
 * 支付插件实例化。
 *
 * 商品下单（Service\Bind\Order）和余额充值（Service\Bind\Recharge）以前各自抄了一份一模一样的
 * 「拼类名 → 加载插件自带 Vendor → new → 塞 8 个公开属性」，配置改成多档之后这段还要再改一次，
 * 索性收到一处。两边真正不同的部分（手续费算法、收银台URL、跳转地址）仍留在各自的调用点。
 */
class PayFactory
{
    /**
     * @param PayModel $pay 支付接口行，决定用哪个插件、哪个通道、哪套配置
     * @param string $tradeNo 订单号
     * @param float $amount 含手续费的最终金额，调用方算好再传进来
     * @param string $callbackUrl 异步通知地址
     * @param string $returnUrl 支付完成后的跳转地址
     * @param string $clientIp 客户IP
     * @return Base
     * @throws JSONException
     */
    public static function make(
        PayModel $pay,
        string   $tradeNo,
        float    $amount,
        string   $callbackUrl,
        string   $returnUrl,
        string   $clientIp
    ): Base
    {
        $handle = (string)$pay->handle;
        $class = "\\App\\Pay\\{$handle}\\Impl\\Pay";

        if (!class_exists($class)) {
            throw new JSONException("该支付方式未实现接口，无法使用");
        }

        //插件可以自带一份 composer 依赖
        $autoload = BASE_PATH . '/app/Pay/' . $handle . "/Vendor/autoload.php";
        if (file_exists($autoload)) {
            require($autoload);
        }

        //配置取不到会在这里抛出，早于 trade()，不会出现「跟网关下完单才发现没配置」
        $config = PayProfile::config($pay);

        /**
         * @var Base $payObject
         */
        $payObject = new $class;
        $payObject->amount = $amount;
        $payObject->tradeNo = $tradeNo;
        $payObject->config = $config;
        $payObject->callbackUrl = $callbackUrl;
        $payObject->returnUrl = $returnUrl;
        $payObject->clientIp = $clientIp;
        $payObject->code = (string)$pay->code;
        $payObject->handle = $handle;

        return $payObject;
    }
}
