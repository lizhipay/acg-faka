<?php
declare(strict_types=1);

namespace App\Service\Impl;


use App\Entity\PayEntity;
use App\Model\Bill;
use App\Model\Config;
use App\Model\OrderOption;
use App\Model\Pay;
use App\Model\UserRecharge;
use App\Service\Order;
use App\Service\Recharge;
use App\Util\Client;
use App\Util\Date;
use App\Util\PayConfig;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;

class RechargeService implements Recharge
{

    #[Inject]
    private Order $order;

    /**
     * @param \App\Model\User $user
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function trade(\App\Model\User $user): array
    {
        $payId = (int)$_POST['pay_id'];//支付方式id
        $amount = (float)$_POST['amount'];//充值金额

        $rechargeMin = (float)Config::get("recharge_min");
        $rechargeMin = $rechargeMin == 0 ? 10 : $rechargeMin;
        $rechargeMax = (float)Config::get("recharge_max");

        if ($amount < $rechargeMin) {
            throw new JSONException("单次最低充值{$rechargeMin}元");
        }

        if ($amount > $rechargeMax && $rechargeMax > 0 && $rechargeMax > $rechargeMin) {
            throw new JSONException("单次最高充值{$rechargeMax}元");
        }

        $pay = Pay::query()->find($payId);

        if (!$pay) {
            throw new JSONException("请选择支付方式");
        }

        if ($pay->recharge != 1) {
            throw new JSONException("当前支付方式已停用");
        }

        //回调地址
        $callbackDomain = trim(Config::get("callback_domain"), "/");
        $clientDomain = Client::getUrl();

        if (!$callbackDomain) {
            $callbackDomain = $clientDomain;
        }
 
        return Db::transaction(function () use ($user, $pay, $amount, $callbackDomain, $clientDomain) {
            $order = new UserRecharge();
            $order->trade_no = Str::generateTradeNo();
            $order->user_id = $user->id;
            $order->amount = $amount;
            $order->pay_id = $pay->id;
            $order->status = 0;
            $order->create_time = Date::current();
            $order->create_ip = Client::getAddress();

            $class = "\\App\\Pay\\{$pay->handle}\\Impl\\Pay";
            if (!class_exists($class)) {
                throw new JSONException("该支付方式未实现接口，无法使用");
            }
            $autoload = BASE_PATH . '/app/Pay/' . $pay->handle . "/Vendor/autoload.php";
            if (file_exists($autoload)) {
                require($autoload);
            }
            //增加接口手续费：0.9.6-beta
            $order->amount = $order->amount + ($pay->cost_type == 0 ? $pay->cost : $order->amount * $pay->cost);
            $order->amount = (float)sprintf("%.2f", (int)(string)($order->amount * 100) / 100);

            $payObject = new $class;
            $payObject->amount = $order->amount;
            $payObject->tradeNo = $order->trade_no;
            $payObject->config = PayConfig::config($pay->handle);
            $payObject->callbackUrl = $callbackDomain . '/user/api/rechargeNotification/callback.' . $pay->handle;
            $payObject->returnUrl = $clientDomain . '/user/recharge/index';
            $payObject->clientIp = $order->create_ip;
            $payObject->code = $pay->code;
            $payObject->handle = $pay->handle;
            $trade = $payObject->trade();

            if ($trade instanceof PayEntity) {
                $order->pay_url = $trade->getUrl();
                switch ($trade->getType()) {
                    case \App\Pay\Pay::TYPE_REDIRECT:
                        $url = $order->pay_url;
                        break;
                    case \App\Pay\Pay::TYPE_LOCAL_RENDER:
                        $base64 = urlencode(base64_encode('type=1&handle=' . $pay->handle . '&code=' . $pay->code . '&tradeNo=' . $order->trade_no));
                        $url = '/user/recharge/order.' . $base64;
                        break;
                    case \App\Pay\Pay::TYPE_SUBMIT:
                        $order->save();
                        $base64 = urlencode(base64_encode('type=2&tradeNo=' . $order->trade_no));
                        $url = '/user/recharge/order.' . $base64;
                        break;
                }

                $option = $trade->getOption();

                if (!empty($option)) {
                    $order->option = json_encode($option);
                }
            } else {
                throw new JSONException("支付方式未部署成功");
            }

            $order->save();

            return ['url' => $url, 'amount' => $order->amount, 'tradeNo' => $order->trade_no];
        });
    }

    /**
     * @param string $handle
     * @param array $map
     * @return string
     * @throws JSONException
     */
    public function callback(string $handle, array $map): string
    {
        $callback = $this->order->callbackInitialize($handle, $map);
        $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        DB::transaction(function () use ($handle, $map, $callback, $json) {
            //获取订单
            $order = \App\Model\UserRecharge::query()->where("trade_no", $callback['trade_no'])->first();

            if (!$order) {
                PayConfig::log($handle, "CALLBACK-RECHARGE", "订单不存在，接受数据：" . $json);
                throw new JSONException("order not found");
            }

            if ($order->status != 0) {
                PayConfig::log($handle, "CALLBACK-RECHARGE", "重复通知，当前订单已支付");
                throw new JSONException("order status error");
            }

            if ($order->amount != $callback['amount']) {
                PayConfig::log($handle, "CALLBACK-RECHARGE", "订单金额不匹配，接受数据：" . $json);
                throw new JSONException("amount error");
            }

            //订单更新
            $this->orderSuccess($order);
        });

        return $callback['success'];
    }


    /**
     * @param \App\Model\UserRecharge $recharge
     * @throws \Kernel\Exception\JSONException
     */
    public function orderSuccess(UserRecharge $recharge): void
    {
        $recharge->status = 1;
        $recharge->pay_time = Date::current();
        $recharge->option = null;

        //充值
        $user = $recharge->user;

        if ($user) {
            $rechargeWelfareAmount = $this->calcAmount($recharge->amount);
            Bill::create($user, $recharge->amount, Bill::TYPE_ADD, "充值", 0); //用户余额
            if ($rechargeWelfareAmount > 0) {
                Bill::create($user, $rechargeWelfareAmount, Bill::TYPE_ADD, "充值赠送", 0); //用户余额
            }
        }

        $recharge->save();
    }


    /**
     * @param float $amount
     * @return float
     * @throws \Kernel\Exception\JSONException
     */
    public function calcAmount(float $amount): float
    {
        $price = 0;
        $rechargeWelfare = (int)Config::get("recharge_welfare");
        if ($rechargeWelfare == 1) {
            $list = [];
            $rechargeWelfareconfig = explode(PHP_EOL, trim(Config::get("recharge_welfare_config"), PHP_EOL));
            foreach ($rechargeWelfareconfig as $item) {
                $s = explode('-', $item);
                if (count($s) == 2) {
                    $list[$s[0]] = $s[1];
                }
            }
            krsort($list);
            foreach ($list as $k => $v) {
                if ($amount >= $k) {
                    $price = $v;
                    break;
                }
            }
        }
        return (float)$price;
    }


}