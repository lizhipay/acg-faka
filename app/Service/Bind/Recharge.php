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
use App\Util\Currency;
use App\Util\Date;
use App\Util\PayFactory;
use App\Util\PayProfile;
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
            //稳定文案走翻译，金额带站点货币符号拼在后面（符号本身不进翻译）
            throw new JSONException(lang("单次最低充值") . " " . Currency::symbol() . $rechargeMin);
        }

        if ($amount > $rechargeMax && $rechargeMax > 0 && $rechargeMax > $rechargeMin) {
            throw new JSONException(lang("单次最高充值") . " " . Currency::symbol() . $rechargeMax);
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

            //增加接口手续费：0.9.6-beta。手续费单独记入 pay_cost（与商品订单一致），
            //到账时用 amount - pay_cost 还原充值面额，否则手续费会被一并充进余额（issue #783）
            $order->pay_cost = (float)($pay->cost_type == 0
                ? (new \Kernel\Util\Decimal((string)$pay->cost, 2))->getAmount()
                : (new \Kernel\Util\Decimal((string)$order->amount, 2))->mul((string)$pay->cost)->getAmount());
            $order->amount = (float)(new \Kernel\Util\Decimal((string)$order->amount, 2))->add((string)$order->pay_cost)->getAmount();

            //站点货币 → CNY 快照（与商品订单同构）：传给插件与回调比对都认这份快照
            $order->gateway_amount = Currency::toCny($order->amount);

            $payObject = PayFactory::make(
                $pay,
                (string)$order->trade_no,
                (float)$order->gateway_amount,
                $callbackDomain . '/user/api/rechargeNotification/callback.' . $order->trade_no,
                $clientDomain . '/user/recharge/index',
                (string)$order->create_ip
            );
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
     * @param string $tradeNo 回调URL上带的订单号
     * @param array $map
     * @return string
     * @throws JSONException
     * @throws RuntimeException
     * @throws \HTMLPurifier_Exception
     * @throws \ReflectionException
     */
    public function callback(string $tradeNo, array $map): string
    {
        $reject = \App\Service\Bind\Order::CALLBACK_REJECT;
        $tradeNo = Firewall::inst()->xssKiller($tradeNo);

        //与商品订单回调完全同构：URL带的就是订单号，按插件名寻址已彻底移除
        if (!\App\Service\Bind\Order::isCallbackTradeNo($tradeNo)) {
            \App\Service\Bind\Order::callbackFail('', "handle", $reject, null, $map, null, "CALLBACK-RECHARGE");
        }

        $order = UserRecharge::with(['pay'])->where("trade_no", $tradeNo)->first();

        if (!$order || !$order->pay) {
            \App\Service\Bind\Order::callbackFail('', "not_found", $reject, $tradeNo, $map, null, "CALLBACK-RECHARGE");
        }

        $handle = (string)$order->pay->handle;

        //这笔充值当初用的是哪套配置，就用哪套验签
        try {
            $payConfig = PayProfile::config($order->pay);
        } catch (JSONException $e) {
            \App\Service\Bind\Order::callbackFail($handle, "config", $reject, $tradeNo, $map, "支付配置不存在，无法验签：" . $e->getMessage(), "CALLBACK-RECHARGE");
            return $reject; //callbackFail 必然抛出，这行只为静态分析
        }

        $callback = $this->order->callbackInitialize($order->pay, $map, $payConfig);

        //★ 与商品订单同理，这一步防的是「拿一份合法签名的回调去刷别人的充值单」。
        //充值金额是用户自己填的，缺了这个校验等于可以无限刷余额。
        $verifiedTradeNo = (string)($callback['trade_no'] ?? '');
        if ($verifiedTradeNo === '' || !hash_equals((string)$order->trade_no, $verifiedTradeNo)) {
            \App\Service\Bind\Order::callbackFail($handle, "mismatch", $reject, (string)$order->trade_no, $map, "报文中取不到订单号、或与回调地址的订单号不一致，无法确认这笔回调属于本单，已拒绝", "CALLBACK-RECHARGE");
        }

        $tradeNo = (string)$order->trade_no;

        //与订单回调一致：串行化隔离，防并发重复通知造成重复入账
        DB::connection()->getPdo()->exec("set session transaction isolation level serializable");
        DB::transaction(function () use ($handle, $map, $callback, $tradeNo, $reject) {
            //获取订单
            $order = UserRecharge::query()->where("trade_no", $tradeNo)->first();

            if (!$order) {
                \App\Service\Bind\Order::callbackFail($handle, "not_found", $reject, $tradeNo, $map, "订单不存在", "CALLBACK-RECHARGE");
            }

            if ((int)$order->status !== 0) {
                \App\Service\Bind\Order::callbackFail($handle, "duplicate", $reject, $tradeNo, $map, "重复通知，当前订单已支付", "CALLBACK-RECHARGE");
            }

            //同商品订单：先卡类型再用 bcmath 精确比对，(float)数组==1.0 的坑不能踩
            $paidAmount = $callback['amount'] ?? null;
            if (!is_scalar($paidAmount) || !is_numeric((string)$paidAmount)) {
                \App\Service\Bind\Order::callbackFail($handle, "amount", $reject, $tradeNo, $map, "回调金额不是合法数字", "CALLBACK-RECHARGE");
            }
            //比对基准是下单时的 CNY 快照（gateway_amount），NULL 仅出现在升级前的在途订单
            $expectSource = $order->gateway_amount !== null ? (string)$order->gateway_amount : (string)$order->amount;
            $expectAmount = (new \Kernel\Util\Decimal($expectSource, 2))->getAmount();
            $actualAmount = (new \Kernel\Util\Decimal((string)$paidAmount, 2))->getAmount();
            if (!hash_equals($expectAmount, $actualAmount)) {
                \App\Service\Bind\Order::callbackFail($handle, "amount", $reject, $tradeNo, $map, "订单金额不匹配", "CALLBACK-RECHARGE");
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

        //充值：到账金额 = 支付金额 - 接口手续费（手续费由用户承担，但不应充进余额）
        $user = $recharge->user;

        if ($user) {
            $credit = (float)(new \Kernel\Util\Decimal((string)$recharge->amount, 2))->sub((string)($recharge->pay_cost ?? 0))->getAmount();
            $rechargeWelfareAmount = $this->calcAmount($credit);
            Bill::create($user, $credit, Bill::TYPE_ADD, "充值", 0); //用户余额
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