<?php
declare(strict_types=1);

namespace App\Service\Impl;


use App\Entity\PayEntity;
use App\Model\Bill;
use App\Model\Card;
use App\Model\Commodity;
use App\Model\Config;
use App\Model\Coupon;
use App\Model\OrderOption;
use App\Model\Pay;
use App\Model\User;
use App\Model\UserGroup;
use App\Service\Email;
use App\Service\Order;
use App\Service\Shared;
use App\Util\Client;
use App\Util\Date;
use App\Util\PayConfig;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager as DB;
use JetBrains\PhpStorm\ArrayShape;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;

class OrderService implements Order
{
    #[Inject]
    private Shared $shared;

    #[Inject]
    private Email $email;

    /**
     * @param int $owner
     * @param int $num
     * @param \App\Model\Commodity $commodity
     * @return float
     */
    public function calcAmount(int $owner, int $num, Commodity $commodity): float
    {
        $price = $owner == 0 ? $commodity->price : $commodity->user_price;
        if ($commodity->lot_status == 1) {
            $list = [];
            $wholesales = explode(PHP_EOL, trim(trim((string)$commodity->lot_config), PHP_EOL));
            foreach ($wholesales as $item) {
                $s = explode('-', $item);
                if (count($s) == 2) {
                    $list[$s[0]] = $s[1];
                }
            }
            krsort($list);
            foreach ($list as $k => $v) {
                if ($num >= $k) {
                    $price = $v;
                    break;
                }
            }
        }
        return $num * $price;
    }

    /**
     * @param \App\Model\User|null $user
     * @param \App\Model\UserGroup|null $userGroup
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function trade(?User $user, ?UserGroup $userGroup): array
    {
        #CFG begin
        $commodityId = (int)$_POST['commodity_id'];//商品ID
        $contact = (string)$_POST['contact'];//联系方式
        $num = (int)$_POST['num']; //购买数量
        $cardId = (int)$_POST['card_id'];//预选的卡号ID
        $payId = (int)$_POST['pay_id'];//支付方式id
        $device = (int)$_POST['device'];//设备
        $password = (string)$_POST['password'];//查单密码
        $coupon = (string)$_POST['coupon'];//优惠卷
        $from = (int)$_POST['from'];//推广人ID
        $owner = $user == null ? 0 : $user->id;
        #CFG end

        if ($commodityId == 0) {
            throw new JSONException("请选择商品在下单");
        }

        if ($num <= 0) {
            throw new JSONException("至少购买1个");
        }

        $commodity = Commodity::query()->find($commodityId);

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        if ($commodity->status != 1) {
            throw new JSONException("当前商品已停售");
        }

        if ($commodity->only_user == 1 || $commodity->purchase_count > 0) {
            if ($owner == 0) {
                throw new JSONException("请先登录后再购买哦");
            }
        }


        //预选卡密
        if ($commodity->draft_status == 1 && $cardId != 0) {
            $num = 1;
        }

        if (mb_strlen($contact) < 3) {
            throw new JSONException("联系方式不能低于3个字符");
        }

        $regx = ['/^1[3456789]\d{9}$/', '/.*(.{2}@.*)$/i', '/[1-9]{1}[0-9]{4,11}/'];
        $msg = ['手机', '邮箱', 'QQ号'];

        //联系方式正则判断
        if ($commodity->contact_type != 0) {
            if (!preg_match($regx[$commodity->contact_type - 1], $contact)) {
                throw new JSONException("您输入的{$msg[$commodity->contact_type - 1]}格式不正确！");
            }
        }

        if ($commodity->password_status == 1 && mb_strlen($password) < 6) {
            throw new JSONException("您的设置的密码过于简单，不能低于6位哦");
        }


        if ($commodity->seckill_status == 1) {
            if (time() < strtotime($commodity->seckill_start_time)) {
                throw new JSONException("抢购还未开始");
            }
            if (time() > strtotime($commodity->seckill_end_time)) {
                throw new JSONException("抢购已结束");
            }
        }

        $shared = $commodity->shared; //获取商品的共享平台

        if ($shared) {
            if (!$this->shared->inventoryState($shared, $commodity->shared_code, $cardId, $num)) {
                throw new JSONException("库存不足");
            }
        } else {
            //自动发货，库存检测
            if ($commodity->delivery_way == 0) {
                $count = Card::query()->where("commodity_id", $commodityId)->where("status", 0)->count();
                if ($count == 0 || $num > $count) {
                    throw new JSONException("库存不足");
                }
            }
        }

        if ($commodity->purchase_count > 0 && $owner > 0) {
            $orderCount = \App\Model\Order::query()->where("owner", $owner)->where("commodity_id", $commodity->id)->count();
            if ($orderCount >= $commodity->purchase_count) {
                throw new JSONException("该商品每人只能购买{$commodity->purchase_count}件");
            }
        }

        //计算订单基础价格
        $amount = $this->calcAmount($owner, $num, $commodity);
        //判断预选费用

        $pay = Pay::query()->find($payId);

        if (!$pay) {
            throw new JSONException("该支付方式不存在");
        }

        if ($pay->commodity != 1) {
            throw new JSONException("当前支付方式已停用，请换个支付方式再进行支付");
        }

        return Db::transaction(function () use ($userGroup, $num, $contact, $device, $amount, $owner, $commodity, $pay, $cardId, $password, $coupon, $from) {
            $date = Date::current();
            $order = new  \App\Model\Order();
            $order->owner = $owner;
            $order->trade_no = Str::generateTradeNo();
            $order->amount = $amount;
            $order->commodity_id = $commodity->id;
            $order->pay_id = $pay->id;
            $order->create_time = $date;
            $order->create_ip = Client::getAddress();
            $order->create_device = $device;
            $order->status = 0;
            $order->contact = $contact;
            $order->delivery_status = 0;
            $order->card_num = $num;
            $order->user_id = (int)$commodity->owner;
            if ($from != 0 && $order->user_id != $from) {
                $order->from = $from;
            }

            if ($commodity->draft_status == 1 && $cardId != 0) {
                $card = Card::query()->find($cardId);
                if (!$card || $card->status != 0) {
                    throw new JSONException("该卡已被他人抢走啦");
                }

                if ($card->commodity_id != $commodity->id) {
                    throw new JSONException("该卡密不属于这个商品，无法预选");
                }

                //加钱
                $order->amount = $order->amount + $commodity->draft_premium;
                $order->card_id = $cardId;
            }

            if ($password != "") {
                $order->password = $password;
            }

            //用户组减免
            if ($userGroup) {
                $order->amount = $order->amount - ($order->amount * $userGroup->discount);
            }

            //优惠卷
            if ($coupon != "") {
                $voucher = Coupon::query()->where("code", $coupon)->first();
                if (!$voucher) {
                    throw new JSONException("该优惠卷不存在");
                }

                if ($voucher->commodity_id != $commodity->id) {
                    throw new JSONException("该优惠卷不属于该商品");
                }

                if ($voucher->status != 0) {
                    throw new JSONException("该优惠卷无法使用");
                }

                //检测过期时间
                if ($voucher->expire_time != null && strtotime($voucher->expire_time) < time()) {
                    throw new JSONException("该优惠卷已过期");
                }

                //检测面额
                if ($voucher->money >= $order->amount) {
                    throw new JSONException("该优惠卷面额大于订单金额");
                }

                //进行优惠
                $order->amount = $order->amount - $voucher->money;
                $voucher->service_time = $date;
                $voucher->status = 1;
                $voucher->trade_no = $order->trade_no;
                $voucher->save();
                $order->coupon_id = $voucher->id;
            }

            $secret = null;

            if ($pay->handle == "#system") {
                //余额购买
                if ($owner == 0) {
                    throw new JSONException("您未登录，请先登录后再使用余额支付");
                }
                $session = User::query()->find($owner);
                if (!$session) {
                    throw new JSONException("用户不存在");
                }

                if ($session->status != 1) {
                    throw new JSONException("You have been banned");
                }
                $parent = $session->parent;
                if ($parent && $order->user_id != $from) {
                    $order->from = $parent->id;
                }
                //扣钱
                Bill::create($session, $order->amount, Bill::TYPE_SUB, "商品下单[{$order->trade_no}]");
                //发卡
                $order->save();//先将订单保存下来
                $secret = $this->orderSuccess($order); //提交订单并且获取到卡密信息
            } else {
                //开始进行远程下单
                $class = "\\App\\Pay\\{$pay->handle}\\Impl\\Pay";
                if (!class_exists($class)) {
                    throw new JSONException("该支付方式未实现接口，无法使用");
                }
                $autoload = BASE_PATH . '/app/Pay/' . $pay->handle . "/Vendor/autoload.php";
                if (file_exists($autoload)) {
                    require($autoload);
                }
                $payObject = new $class;
                $payObject->amount = (float)sprintf("%.2f", $order->amount);
                $payObject->tradeNo = $order->trade_no;
                $payObject->config = PayConfig::config($pay->handle);
                $payObject->callbackUrl = Client::getUrl() . '/user/api/order/callback.' . $pay->handle;
                $payObject->returnUrl = Client::getUrl() . '/user/index/query?tradeNo=' . $order->trade_no;

                $payObject->clientIp = Client::getAddress();
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
                            $url = '/user/pay/order.' . $base64;
                            break;
                        case \App\Pay\Pay::TYPE_SUBMIT:
                            $base64 = urlencode(base64_encode('type=2&tradeNo=' . $order->trade_no));
                            $url = '/user/pay/order.' . $base64;
                            break;
                    }
                    $order->save();
                    $option = $trade->getOption();
                    if (!empty($option)) {
                        OrderOption::create($order->id, $trade->getOption());
                    }
                } else {
                    throw new JSONException("支付方式未部署成功");
                }
            }

            $order->save();

            return ['url' => $url, 'amount' => $order->amount, 'tradeNo' => $order->trade_no, 'secret' => $secret];
        });
    }


    /**
     * 初始化回调
     * @throws \Kernel\Exception\JSONException
     */
    #[ArrayShape(["trade_no" => "mixed", "amount" => "mixed", "success" => "mixed"])] public function callbackInitialize(string $handle, array $map): array
    {
        $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $payInfo = PayConfig::info($handle);
        $payConfig = PayConfig::config($handle);
        $callback = $payInfo['callback'];

        $autoload = BASE_PATH . '/app/Pay/' . $handle . "/Vendor/autoload.php";
        if (file_exists($autoload)) {
            require($autoload);
        }

        //检测签名验证是否开启
        if ($callback[\App\Consts\Pay::IS_SIGN]) {
            $class = "\\App\\Pay\\{$handle}\\Impl\\Signature";
            if (!class_exists($class)) {
                PayConfig::log($handle, "CALLBACK", "插件未实现接口");
                throw new JSONException("signature not implements interface");
            }
            $signature = new $class;
            if (!$signature->verification($map, $payConfig)) {
                PayConfig::log($handle, "CALLBACK", "签名验证失败，接受数据：" . $json);
                throw new JSONException("sign error");
            }
        }

        //验证状态
        if ($callback[\App\Consts\Pay::IS_STATUS]) {
            if ($map[$callback[\App\Consts\Pay::FIELD_STATUS_KEY]] != $callback[\App\Consts\Pay::FIELD_STATUS_VALUE]) {
                PayConfig::log($handle, "CALLBACK", "状态验证失败，接受数据：" . $json);
                throw new JSONException("status error");
            }
        }

        //拿到订单号和金额
        return ["trade_no" => $map[$callback[\App\Consts\Pay::FIELD_ORDER_KEY]], "amount" => $map[$callback[\App\Consts\Pay::FIELD_AMOUNT_KEY]], "success" => $callback[\App\Consts\Pay::FIELD_RESPONSE]];
    }


    public function orderSuccess(\App\Model\Order $order): string
    {
        $commodity = $order->commodity;
        $order->pay_time = Date::current();
        $order->status = 1;
        $shared = $commodity->shared; //获取商品的共享平台

        if ($shared) {
            //拉取远程平台的卡密发货
            $order->secret = $this->shared->trade($shared, $commodity->shared_code, $order->contact, $order->card_num, (int)$order->card_id, $order->create_device, $order->password);
            $order->delivery_status = 1;
        } else {
            //自动发货
            if ($commodity->delivery_way == 0) {
                //拉取本地的卡密发货
                $order->secret = $this->pullCardForLocal($order, $commodity);
                $order->delivery_status = 1;
            } else {
                //手动发货
                $order->secret = ($commodity->delivery_message != null && $commodity->delivery_message != "") ? $commodity->delivery_message : '正在发货中，请耐心等待，如有疑问，请联系客服。';
            }
        }

        //佣金
        $merchant = $order->user;
        if ($merchant) {
            //获取返佣比例
            $userGroup = UserGroup::get($merchant->recharge);
            $order->cost = $order->amount * $userGroup->cost; //手续费
            Bill::create($merchant, $order->amount - $order->cost, Bill::TYPE_ADD, "商品出售[$order->trade_no]", 1);
        }

        //真 · 返佣
        $promote = $order->promote;
        if ($promote) {
            $promoteRebateV1 = (float)Config::get("promote_rebate_v1");
            $rebate = $promoteRebateV1 * $order->amount;
            if ($rebate >= 0.01) {
                Bill::create($promote, $rebate, Bill::TYPE_ADD, "推广返佣", 1);
                $parent = $promote->parent;
                if ($parent) {
                    //二级返佣
                    $promoteRebateV2 = (float)Config::get("promote_rebate_v2");
                    $rebate = ($promoteRebateV2 * $order->amount) - $rebate;
                    if ($rebate >= 0.01) {
                        Bill::create($parent, $rebate, Bill::TYPE_ADD, "推广返佣", 1);
                        $parent = $parent->parent;
                        if ($parent) {
                            //三级返佣
                            $promoteRebateV3 = (float)Config::get("promote_rebate_v3");
                            $rebate = ($promoteRebateV3 * $order->amount) - $rebate;
                            if ($rebate >= 0.01) {
                                Bill::create($parent, $rebate, Bill::TYPE_ADD, "推广返佣", 1);
                            }
                        }
                    }
                }
            }
        }

        $order->save();

        if ($commodity->contact_type == 2 && $commodity->send_email == 1) {
            try {
                $this->email->send($order->contact, "【发货提醒】您购买的卡密发货啦", "您购买的卡密如下：" . $order->secret);
            } catch (\Exception | \Error $e) {
            }
        }

        return (string)$order->secret;
    }

    /**
     * 拉取本地卡密，需要事务环境执行
     * @param \App\Model\Order $order
     * @param \App\Model\Commodity $commodity
     * @return string
     */
    private function pullCardForLocal(\App\Model\Order $order, Commodity $commodity): string
    {
        $secret = "很抱歉，有人在你付款之前抢走了商品，请联系客服。";

        $draft = $order->card;

        //指定预选卡密
        if ($draft) {
            if ($draft->status == 0) {
                $secret = $draft->secret;
                $draft->purchase_time = $order->pay_time;
                $draft->order_id = $order->id;
                $draft->status = 1;
                $draft->save();
            }
            return $secret;
        }

        //取出和订单相同数量的卡密
        $direction = match ($commodity->delivery_auto_mode) {
            0 => "id asc",
            1 => "rand()",
            2 => "id desc"
        };

        $cards = Card::query()->where("commodity_id", $order->commodity_id)->orderByRaw($direction)->where("status", 0)->limit($order->card_num)->get();

        if (count($cards) == $order->card_num) {
            $ids = [];
            $cardc = '';
            foreach ($cards as $card) {
                $ids[] = $card->id;
                $cardc .= $card->secret . PHP_EOL;
            }
            try {
                //将全部卡密置已销售状态
                $rows = Card::query()->whereIn("id", $ids)->update(['purchase_time' => $order->pay_time, 'order_id' => $order->id, 'status' => 1]);
                if ($rows != 0) {
                    $secret = trim($cardc, PHP_EOL);
                }
            } catch (\Exception $e) {
            }
        }

        return $secret;
    }


    /**
     * @param string $handle
     * @param array $map
     * @return string
     * @throws \Kernel\Exception\JSONException
     */
    public function callback(string $handle, array $map): string
    {
        $callback = $this->callbackInitialize($handle, $map);
        $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        DB::transaction(function () use ($handle, $map, $callback, $json) {
            //获取订单
            $order = \App\Model\Order::query()->where("trade_no", $callback['trade_no'])->first();
            if (!$order) {
                PayConfig::log($handle, "CALLBACK", "订单不存在，接受数据：" . $json);
                throw new JSONException("order not found");
            }

            if ($order->status != 0) {
                PayConfig::log($handle, "CALLBACK", "重复通知，当前订单已支付");
                throw new JSONException("order status error");
            }

            if ($order->amount != $callback['amount']) {
                PayConfig::log($handle, "CALLBACK", "订单金额不匹配，接受数据：" . $json);
                throw new JSONException("amount error");
            }

            $this->orderSuccess($order);
        });
        return $callback['success'];
    }

    /**
     * @param \App\Model\User|null $user
     * @param \App\Model\UserGroup|null $userGroup
     * @param int $cardId
     * @param int $num
     * @param string $coupon
     * @param int $commodityId
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    #[ArrayShape(["amount" => "mixed", "price" => "float|int", "couponMoney" => "float|int"])] public function getTradeAmount(?User $user, ?UserGroup $userGroup, int $cardId, int $num, string $coupon, int $commodityId): array
    {
        if ($num <= 0) {
            throw new JSONException("购买数量不能低于1个");
        }
        //查询商品
        $commodity = Commodity::query()->find($commodityId);

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }
        if ($commodity->status != 1) {
            throw new JSONException("当前商品已停售");
        }

        if ($cardId != 0 && $commodity->draft_status == 1) {
            $commodity->price = $commodity->price + $commodity->draft_premium;
            $commodity->user_price = $commodity->user_price + $commodity->draft_premium;
            $num = 1;
        }

        $ow = 0;
        if ($user) {
            $ow = $user->id;
        }
        $amount = $this->calcAmount($ow, $num, $commodity);
        if ($userGroup) {
            $amount = $amount - ($userGroup->discount * $amount);
        }
        $couponMoney = 0;
        //优惠卷
        $price = $amount / $num;
        if ($coupon != "") {
            $code = Coupon::query()->where("code", $coupon)->first();
            if (!$code || $code->commodity_id != $commodityId) {
                throw new JSONException("该优惠卷不存在或不属于该商品");
            }
            if ($code->status != 0) {
                throw new JSONException("该优惠卷已被使用过了");
            }
            if ($code->money > $amount) {
                throw new JSONException("该优惠卷抵扣的金额大于本次消费，无法使用该优惠卷进行抵扣");
            }
            if ($code->expire_time != null && strtotime($code->expire_time) < time()) {
                throw new JSONException("该优惠卷已过期");
            }

            $amount = $amount - $code->money;
            $couponMoney = $code->money;
        }
        return ["amount" => sprintf("%.2f", $amount), "price" => sprintf("%.2f", $price), "couponMoney" => sprintf("%.2f", $couponMoney)];
    }
}