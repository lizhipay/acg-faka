<?php
declare(strict_types=1);

namespace App\Service\Bind;


use App\Consts\Hook;
use App\Entity\PayEntity;
use App\Model\Bill;
use App\Model\Config;
use App\Model\OrderOption;
use App\Model\Pay;
use App\Model\User;
use App\Model\UserRecharge;
use App\Service\Order;
use App\Util\Client;
use App\Util\Date;
use App\Util\PayConfig;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Waf\Firewall;

class Recharge implements \App\Service\Recharge
{

    #[Inject]
    private Order $order;

    /**
     * @param User $user
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     */
    public function trade(User $user): array
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
                        $url = '/user/recharge/order.' . $order->trade_no . ".1";
                        break;
                    case \App\Pay\Pay::TYPE_SUBMIT:
                        $url = '/user/recharge/order.' . $order->trade_no . ".2";
                        break;
                }

                $order->save();

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
     * @throws RuntimeException
     * @throws \HTMLPurifier_Exception
     * @throws \ReflectionException
     */
    public function callback(string $handle, array $map): string
    {
        $handle = Firewall::inst()->xssKiller($handle);
        if (!Str::isValid($handle) || !PayConfig::isValid($handle)) {
            throw new JSONException("handle not found");
        }

        $tradeNo = $this->order->getCallbackTradeNo($handle, $map);

        if (!$tradeNo) {
            throw new JSONException("order number not found");
        }

        $order = UserRecharge::with(['pay'])->where("trade_no", $tradeNo)->first();

        if (!$order->pay) {
            throw new JSONException("pay not found");
        }

        if ($order->pay->handle !== $handle) {
            throw new JSONException("pay handle not found");
        }

        $callback = $this->order->callbackInitialize($handle, $map);

        //与订单回调一致：串行化隔离，防并发重复通知造成重复入账
        DB::connection()->getPdo()->exec("set session transaction isolation level serializable");
        DB::transaction(function () use ($handle, $map, $callback) {
            //获取订单
            $order = UserRecharge::query()->where("trade_no", $callback['trade_no'])->first();

            if (!$order) {
                PayConfig::log($handle, "CALLBACK-RECHARGE", "订单不存在");
                throw new JSONException("order not found");
            }

            if ((int)$order->status !== 0) {
                PayConfig::log($handle, "CALLBACK-RECHARGE", "重复通知，当前订单已支付");
                throw new JSONException("order status error");
            }

            if ($order->amount !== (float)$callback['amount']) {
                PayConfig::log($handle, "CALLBACK-RECHARGE", "订单金额不匹配");
                throw new JSONException("amount error");
            }

            //订单更新
            $this->orderSuccess($order);
        });

        return $callback['success'];
    }


    /**
     * @param UserRecharge $recharge
     * @throws JSONException
     * @throws RuntimeException
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

        $pay = $recharge->pay;
        hook(Hook::USER_API_RECHARGE_AFTER, $recharge, $pay);

        $recharge->save();
    }


    /**
     * @param float $amount
     * @return float
     * @throws RuntimeException
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