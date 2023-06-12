<?php
declare(strict_types=1);

namespace App\Service\Impl;


use App\Entity\PayEntity;
use App\Model\Bill;
use App\Model\Business;
use App\Model\BusinessLevel;
use App\Model\Card;
use App\Model\Commodity;
use App\Model\Config;
use App\Model\Coupon;
use App\Model\OrderOption;
use App\Model\Pay;
use App\Model\User;
use App\Model\UserCommodity;
use App\Model\UserGroup;
use App\Service\Email;
use App\Service\Order;
use App\Service\Shared;
use App\Util\Client;
use App\Util\Date;
use App\Util\Ini;
use App\Util\PayConfig;
use App\Util\Str;
use App\Util\Validation;
use Illuminate\Database\Capsule\Manager as DB;
use JetBrains\PhpStorm\ArrayShape;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;
use Kernel\Util\Context;

class OrderService implements Order
{
    #[Inject]
    private Shared $shared;

    #[Inject]
    private Email $email;

    /**
     * @param int $owner
     * @param int $num
     * @param Commodity $commodity
     * @param UserGroup|null $group
     * @param string|null $race
     * @param bool $disableSubstation
     * @return float
     * @throws JSONException
     */
    public function calcAmount(int $owner, int $num, Commodity $commodity, ?UserGroup $group, ?string $race = null, bool $disableSubstation = false): float
    {
        $premium = 0;

        //检测分站价格
        $bus = \App\Model\Business::get(Client::getDomain());
        if ($bus && !$disableSubstation) {
            if ($userCommodity = UserCommodity::getCustom($bus->user_id, $commodity->id)) {
                $premium = (float)$userCommodity->premium;
            }
        }

        //解析配置文件
        $this->parseConfig($commodity, $group, $owner, 1, $race);
        $price = $owner == 0 ? $commodity->price : $commodity->user_price;

        //禁用任何折扣,直接计算
        if ($commodity->level_disable == 1) {
            return (int)(string)(($num * ($price + $premium)) * 100) / 100;
        }

        $userDefinedConfig = Commodity::parseGroupConfig((string)$commodity->level_price, $group);


        if ($userDefinedConfig && $userDefinedConfig['amount'] > 0) {
            if (!$commodity->race) {
                //如果自定义价格成功，那么将覆盖其他价格
                $price = $userDefinedConfig['amount'];
            }
        } elseif ($group) {
            //如果没有对应的会员等级解析，那么就直接采用系统折扣
            $price = $price - ($price * $group->discount);
        }

        //判定是race还是普通订单
        if (is_array($commodity->race)) {
            if (array_key_exists((string)$race, (array)$commodity->category_wholesale)) {
                //判定当前race是否可以折扣
                $list = $commodity->category_wholesale[$race];
                krsort($list);
                foreach ($list as $k => $v) {
                    if ($num >= $k) {
                        $price = $v;
                        break;
                    }
                }
            }
        } else {
            //普通订单，直接走批发
            $list = (array)$commodity->wholesale;
            krsort($list);
            foreach ($list as $k => $v) {
                if ($num >= $k) {
                    $price = $v;
                    break;
                }
            }
        }

        $price += $premium; //分站加价
        return (int)(string)(($num * $price) * 100) / 100;
    }


    /**
     * 解析配置
     * @param Commodity $commodity
     * @param UserGroup|null $group
     * @param int $owner
     * @param int $num
     * @param string|null $race
     * @return void
     * @throws JSONException
     */
    public function parseConfig(Commodity &$commodity, ?UserGroup $group, int $owner = 0, int $num = 1, ?string $race = null): void
    {
        $parseConfig = Ini::toArray((string)$commodity->config);
        //用户组解析
        $userDefinedConfig = Commodity::parseGroupConfig($commodity->level_price, $group);

        if ($userDefinedConfig) {
            if (key_exists("category", $userDefinedConfig['config'])) {
                $parseConfig['category'] = $userDefinedConfig['config']['category'];
            }

            if (key_exists("wholesale", $userDefinedConfig['config'])) {
                $parseConfig['wholesale'] = $userDefinedConfig['config']['wholesale'];
            }

            if (key_exists("category_wholesale", $userDefinedConfig['config'])) {
                $parseConfig['category_wholesale'] = $userDefinedConfig['config']['category_wholesale'];
            }
        }

        if (key_exists("category", $parseConfig)) {
            $category = $parseConfig['category'];
            //将类别数组存到对象中
            $commodity->race = $category;
            //判断是否传了指定的类别
            if ($race) {
                if (!key_exists($race, $category)) {
                    throw new JSONException("商品种类不存在");
                }
                $commodity->price = $category[$race];
                $commodity->user_price = $commodity->price;
            } else {
                $commodity->price = current($category);
                $commodity->user_price = $commodity->price;
            }
        }

        //判定批发配置是否配置，如果配置
        if (key_exists("wholesale", $parseConfig)) {
            $wholesale = $parseConfig['wholesale'];
            if (!empty($wholesale)) {
                //将全局批发配置写入到对象中
                $commodity->wholesale = $wholesale;
            }
        }

        if (key_exists("category_wholesale", $parseConfig)) {
            $categoryWholesale = $parseConfig['category_wholesale'];
            if (!empty($categoryWholesale)) {
                //将商品种类批发配置写入到对象中
                $commodity->category_wholesale = $categoryWholesale;
            }
        }

        //成本参数
        if (key_exists("category_factory", $parseConfig)) {
            $categoryFactory = $parseConfig['category_factory'];
            if (!empty($categoryFactory)) {
                $commodity->category_factory = $categoryFactory;
            }
        }
    }

    /**
     * @param Commodity $commodity
     * @param UserGroup|null $group
     * @return array|null
     */
    public function userDefinedPrice(Commodity $commodity, ?UserGroup $group): ?array
    {
        if ($group) {
            $levelPrice = (array)json_decode((string)$commodity->level_price, true);
            return array_key_exists($group->id, $levelPrice) ? $levelPrice[$group->id] : null;
        }
        return null;
    }

    /**
     * @param User|null $user
     * @param UserGroup|null $userGroup
     * @param array $map
     * @return array
     * @throws JSONException
     */
    public function trade(?User $user, ?UserGroup $userGroup, array $map): array
    {
        #CFG begin
        $commodityId = (int)$map['commodity_id'];//商品ID
        $contact = (string)$map['contact'];//联系方式
        $num = (int)$map['num']; //购买数量
        $cardId = (int)$map['card_id'];//预选的卡号ID
        $payId = (int)$map['pay_id'];//支付方式id
        $device = (int)$map['device'];//设备
        $password = (string)$map['password'];//查单密码
        $coupon = (string)$map['coupon'];//优惠卷
        $from = (int)$map['from'];//推广人ID
        $owner = $user == null ? 0 : $user->id;
        $race = (string)$map['race']; //2022/01/09 新增，商品种类功能
        $requestNo = (string)$map['request_no'];
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


        if ($commodity->minimum > 0 && $num < $commodity->minimum) {
            throw new JSONException("本商品最少购买{$commodity->minimum}个");
        }

        if ($commodity->maximum > 0 && $num > $commodity->maximum) {
            throw new JSONException("本商品单次最多购买{$commodity->maximum}个");
        }


        $widget = [];

        //widget
        if ($commodity->widget) {
            $widgetList = (array)json_decode((string)$commodity->widget, true);
            foreach ($widgetList as $item) {
                if ($item['regex'] != "") {
                    if (!preg_match("/{$item['regex']}/", (string)$map[$item['name']])) {
                        throw new JSONException($item['error']);
                    }
                }
                $widget[$item['name']] = [
                    "value" => $map[$item['name']],
                    "cn" => $item['cn']
                ];
            }
        }

        $widget = json_encode($widget, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        //预选卡密
        if ($commodity->draft_status == 1 && $cardId != 0) {
            $num = 1;
        }

        $regx = ['/^1[3456789]\d{9}$/', '/.*(.{2}@.*)$/i', '/[1-9]{1}[0-9]{4,11}/'];
        $msg = ['手机', '邮箱', 'QQ号'];
        //未登录才检测，登录后无需检测
        if (!$user) {
            if (mb_strlen($contact) < 3) {
                throw new JSONException("联系方式不能低于3个字符");
            }
            //联系方式正则判断
            if ($commodity->contact_type != 0) {
                if (!preg_match($regx[$commodity->contact_type - 1], $contact)) {
                    throw new JSONException("您输入的{$msg[$commodity->contact_type - 1]}格式不正确！");
                }
            }
            if ($commodity->password_status == 1 && mb_strlen($password) < 6) {
                throw new JSONException("您的设置的密码过于简单，不能低于6位哦");
            }
        }

        if ($commodity->seckill_status == 1) {
            if (time() < strtotime($commodity->seckill_start_time)) {
                throw new JSONException("抢购还未开始");
            }
            if (time() > strtotime($commodity->seckill_end_time)) {
                throw new JSONException("抢购已结束");
            }
        }

        //解析配置文件且注入对象
        $commodityClone = clone $commodity;
        $this->parseConfig($commodityClone, $userGroup, $owner, $num, $race);

        if ($commodityClone->race && !key_exists($race, $commodityClone->race)) {
            throw new JSONException("请选择商品种类");
        }

        //成本价
        if ($commodityClone->race && $race != "") {
            //获取种类成本
            $factoryPrice = 0;
            if ($commodityClone->category_factory && isset($commodityClone->category_factory[$race])) {
                $factoryPrice = (float)$commodityClone->category_factory[$race];
            }
        } else {
            $factoryPrice = $commodity->factory_price;
        }

        //-------------

        $shared = $commodity->shared; //获取商品的共享平台

        if ($shared) {
            if (!$this->shared->inventoryState($shared, $commodity->shared_code, $cardId, $num, $race)) {
                throw new JSONException("库存不足");
            }
        } else {
            //自动发货，库存检测
            if ($commodity->delivery_way == 0) {
                $count = Card::query()->where("commodity_id", $commodityId)->where("status", 0);
                if ($race) {
                    $count = $count->where("race", $race);
                }
                $count = $count->count();

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
        $amount = $this->calcAmount($owner, $num, $commodity, $userGroup, $race);

        //判断预选费用
        $pay = Pay::query()->find($payId);

        if (!$pay) {
            throw new JSONException("该支付方式不存在");
        }

        if ($pay->commodity != 1) {
            throw new JSONException("当前支付方式已停用，请换个支付方式再进行支付");
        }

        //回调地址
        $callbackDomain = trim(Config::get("callback_domain"), "/");
        $clientDomain = Client::getUrl();

        if (!$callbackDomain) {
            $callbackDomain = $clientDomain;
        }

        DB::connection()->getPdo()->exec("set session transaction isolation level serializable");
        return Db::transaction(function () use ($requestNo, $user, $userGroup, $num, $contact, $device, $amount, $owner, $commodity, $pay, $cardId, $password, $coupon, $from, $widget, $race, $shared, $callbackDomain, $clientDomain, $factoryPrice) {
            //生成联系方式
            if ($user) {
                //检测订单频繁
                //
                $contact = "-";
            }

            if ($requestNo && \App\Model\Order::query()->where("request_no", $requestNo)->first()) {
                throw new JSONException("The request ID already exists");
            }


            $date = Date::current();
            $order = new  \App\Model\Order();
            $order->widget = $widget;
            $order->owner = $owner;
            $order->trade_no = Str::generateTradeNo();
            $order->amount = $amount;
            $order->commodity_id = $commodity->id;
            $order->pay_id = $pay->id;
            $order->create_time = $date;
            $order->create_ip = Client::getAddress();
            $order->create_device = $device;
            $order->status = 0;
            $order->contact = trim((string)$contact);
            $order->delivery_status = 0;
            $order->card_num = $num;
            $order->user_id = (int)$commodity->owner;
            $order->rent = $factoryPrice * $num; //成本价

            if ($requestNo) {
                $order->request_no = $requestNo;
            }


            if ($race) {
                $order->race = $race;
            }

            if ($from != 0 && $order->user_id != $from && $owner != $from) {
                $order->from = $from;
                if (($userCommodity = UserCommodity::getCustom($from, $commodity->id)) && Business::get(Client::getDomain())) {
                    $order->premium = $userCommodity->premium;
                }
            }

            if ($commodity->draft_status == 1 && $cardId != 0) {
                if ($shared) {
                    //加钱
                    $order->amount = $order->amount + $commodity->draft_premium;
                    $order->card_id = $cardId;
                } else {
                    $card = Card::query();
                    if ($race) {
                        $card = $card->where("race", $race);
                    }

                    $card = $card->find($cardId);

                    if (!$card || $card->status != 0) {
                        throw new JSONException("该卡已被他人抢走啦");
                    }

                    if ($card->commodity_id != $commodity->id) {
                        throw new JSONException("该卡密不属于这个商品，无法预选" . $commodity->id);
                    }
                    //加钱
                    $order->amount = $order->amount + $commodity->draft_premium;
                    $order->card_id = $cardId;
                }
            }

            if ($password != "") {
                $order->password = $password;
            }

            //用户组减免
            /*  if ($userGroup) {
                  $order->amount = $order->amount - ($order->amount * $userGroup->discount);
              }*/

            //优惠卷
            if ($coupon != "") {
                $voucher = Coupon::query()->where("code", $coupon)->first();

                if (!$voucher) {
                    throw new JSONException("该优惠卷不存在");
                }

                if ($voucher->owner != $commodity->owner) {
                    throw new JSONException("该优惠卷不存在");
                }

                if ($race && $voucher->commodity_id != 0) {
                    if ($race != $voucher->race) {
                        throw new JSONException("该优惠卷不能抵扣当前商品");
                    }
                }

                if ($voucher->commodity_id != 0 && $voucher->commodity_id != $commodity->id) {
                    throw new JSONException("该优惠卷不属于该商品");
                }

                //判断该优惠卷是否有分类设定
                if ($voucher->commodity_id == 0 && $voucher->category_id != 0 && $voucher->category_id != $commodity->category_id) {
                    throw new JSONException("该优惠卷不能抵扣当前商品");
                }

                if ($voucher->status != 0) {
                    throw new JSONException("该优惠卷已失效");
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
                $order->amount = $voucher->mode == 0 ? $order->amount - $voucher->money : $order->amount - (($order->amount / $order->card_num) * $voucher->money);
                $voucher->service_time = $date;
                $voucher->use_life = $voucher->use_life + 1;
                $voucher->life = $voucher->life - 1;

                if ($voucher->life <= 0) {
                    $voucher->status = 1;
                }

                $voucher->trade_no = $order->trade_no;
                $voucher->save();
                $order->coupon_id = $voucher->id;
            }

            $secret = null;
            $order->amount = (float)sprintf("%.2f", (int)(string)($order->amount * 100) / 100);

            hook(\App\Consts\Hook::USER_API_ORDER_TRADE_PAY_BEGIN, $commodity, $order, $pay);

            if ($order->amount == 0) {
                //免费赠送
                $order->save();//先将订单保存下来
                $secret = $this->orderSuccess($order); //提交订单并且获取到卡密信息
            } else {
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
                    //增加接口手续费：0.9.6-beta
                    $order->pay_cost = $pay->cost_type == 0 ? $pay->cost : $order->amount * $pay->cost;
                    $order->amount = $order->amount + $order->pay_cost;
                    $order->amount = (float)sprintf("%.2f", (int)(string)($order->amount * 100) / 100);

                    $payObject = new $class;
                    $payObject->amount = $order->amount;
                    $payObject->tradeNo = $order->trade_no;
                    $payObject->config = PayConfig::config($pay->handle);

                    $payObject->callbackUrl = $callbackDomain . '/user/api/order/callback.' . $pay->handle;

                    //判断如果登录
                    if ($owner == 0) {
                        $payObject->returnUrl = $clientDomain . '/user/index/query?tradeNo=' . $order->trade_no;
                    } else {
                        $payObject->returnUrl = $clientDomain . '/user/personal/purchaseRecord?tradeNo=' . $order->trade_no;
                    }

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
            }


            $order->save();

            hook(\App\Consts\Hook::USER_API_ORDER_TRADE_AFTER, $commodity, $order, $pay);
            return ['url' => $url, 'amount' => $order->amount, 'tradeNo' => $order->trade_no, 'secret' => $secret];
        });
    }


    /**
     * 初始化回调
     * @throws JSONException
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
            Context::set(\App\Consts\Pay::DAFA, $map);
            if (!$signature->verification($map, $payConfig)) {
                PayConfig::log($handle, "CALLBACK", "签名验证失败，接受数据：" . $json);
                throw new JSONException("sign error");
            }
            $map = Context::get(\App\Consts\Pay::DAFA);
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


    /**
     * @throws JSONException
     */
    public function orderSuccess(\App\Model\Order $order): string
    {
        $commodity = $order->commodity;
        $order->pay_time = Date::current();
        $order->status = 1;
        $shared = $commodity->shared; //获取商品的共享平台

        if ($shared) {
            //拉取远程平台的卡密发货
            $order->secret = $this->shared->trade($shared, $commodity->shared_code, $order->contact, $order->card_num, (int)$order->card_id, $order->create_device, (string)$order->password, (string)$order->race, $order->widget, $order->trade_no);
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
            $businessLevel = $merchant->businessLevel;
            if ($businessLevel) {
                $order->cost = $order->amount * $businessLevel->cost; //手续费
                $a1 = $order->amount - $order->cost - $order->pay_cost;
                if ($a1 > 0) {
                    Bill::create($merchant, $a1, Bill::TYPE_ADD, "商品出售[$order->trade_no]", 1);
                }
            }
        }

        //真 · 返佣
        $promote_1 = $order->promote;

        if ($promote_1) {
            //检测是否分站
            $bus = BusinessLevel::query()->find((int)$promote_1->business_level);
            if ($bus) {
                //查询该商户的拿货价
                $calcAmount = $this->calcAmount($promote_1->id, $order->card_num, $commodity, UserGroup::get($promote_1->recharge), $order->race, true);
                //计算差价
                if ($order->amount > $calcAmount) {
                    $rebate = $order->amount - $calcAmount; //差价
                    $order->premium = $rebate;
                    $a2 = $rebate - ($order->card_id ? $commodity->draft_premium : 0) - $order->pay_cost;
                    if ($rebate >= 0.01 && $a2 > 0) {
                        Bill::create($promote_1, $a2, Bill::TYPE_ADD, "分站返佣", 1);
                        $order->rebate = $a2;
                    }
                }
                //检测到商户等级，进行分站返佣算法 废弃
                // $rebate = ($bus->accrual * ($order->amount - $order->premium)) + $order->premium;   //20.00
            } else {
                //推广系统
                $promoteRebateV1 = (float)Config::get("promote_rebate_v1");  //3级返佣 0.2
                $rebate1 = $promoteRebateV1 * ($order->amount - $order->pay_cost);   //20.00
                if ($rebate1 >= 0.01) {
                    $promote_2 = $promote_1->parent; //获取上级
                    if (!$promote_2) {
                        //没有上级，直接进行1级返佣
                        Bill::create($promote_1, $rebate1, Bill::TYPE_ADD, "推广返佣", 1); //反20.00
                        $order->rebate = $rebate1;
                    } else {
                        $_rebate = 0;
                        //出现上级，开始将返佣的钱继续拆分
                        $promoteRebateV2 = (float)Config::get("promote_rebate_v2"); // 0.4
                        $rebate2 = $promoteRebateV2 * $rebate1; //拿走属于第二级百分比返佣 8.00
                        //先给上级返佣，这里拿掉上级的拿一份
                        Bill::create($promote_1, $rebate1 - $rebate2, Bill::TYPE_ADD, "推广返佣", 1); // 20-8=12.00
                        $_rebate += ($rebate1 - $rebate2);
                        if ($rebate2 > 0.01) { // 8.00
                            $promote_3 = $promote_2->parent; //获取第二级的上级
                            if (!$promote_3) {
                                //没有上级直接进行第二级返佣
                                Bill::create($promote_2, $rebate2, Bill::TYPE_ADD, "推广返佣", 1); // 8.00
                                $_rebate += $rebate2;
                            } else {
                                //出现上级，继续拆分剩下的佣金
                                $promoteRebateV3 = (float)Config::get("promote_rebate_v3"); // 0.4
                                $rebate3 = $promoteRebateV3 * $rebate2; // 8.00 * 0.4 = 3.2
                                //先给上级反
                                Bill::create($promote_2, $rebate2 - $rebate3, Bill::TYPE_ADD, "推广返佣", 1); // 8.00 - 3.2 = 4.8
                                $_rebate += ($rebate2 - $rebate3);
                                if ($rebate3 > 0.01) {
                                    Bill::create($promote_3, $rebate3, Bill::TYPE_ADD, "推广返佣", 1); // 3.2
                                    $_rebate += $rebate3;
                                    //返佣结束  3.2 + 4.8 + 12 = 20.00
                                }
                            }


                            if ($_rebate > 0.01) {
                                $order->rebate = $_rebate;
                            }
                        }
                    }
                }
            }
        }

        $order->save();

        if ($commodity->contact_type == 2 && $commodity->send_email == 1 && $order->owner == 0) {
            try {
                $this->email->send($order->contact, "【发货提醒】您购买的卡密发货啦", "您购买的卡密如下：" . $order->secret);
            } catch (\Exception|\Error $e) {
            }
        }

        hook(\App\Consts\Hook::USER_API_ORDER_PAY_AFTER, $commodity, $order, $order->pay);
        return (string)$order->secret;
    }

    /**
     * 拉取本地卡密，需要事务环境执行
     * @param \App\Model\Order $order
     * @param Commodity $commodity
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
        $cards = Card::query()->where("commodity_id", $order->commodity_id)->orderByRaw($direction)->where("status", 0);
        //判断订单是否存在类别
        if ($order->race) {
            $cards = $cards->where("race", $order->race);
        }

        $cards = $cards->limit($order->card_num)->get();

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
     * @throws JSONException
     */
    public function callback(string $handle, array $map): string
    {
        $callback = $this->callbackInitialize($handle, $map);
        $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        DB::connection()->getPdo()->exec("set session transaction isolation level serializable");
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
            //第三方支付订单成功，累计充值
            if ($order->owner != 0 && $owner = User::query()->find($order->owner)) {
                //累计充值
                $owner->recharge = $owner->recharge + $order->amount;
                $owner->save();
            }
            $this->orderSuccess($order);
        });
        return $callback['success'];
    }

    /**
     * @param User|null $user
     * @param UserGroup|null $userGroup
     * @param int $cardId
     * @param int $num
     * @param string $coupon
     * @param int|Commodity|null $commodityId
     * @param string|null $race
     * @param bool $disableShared
     * @return array
     * @throws JSONException
     */
    #[ArrayShape(["amount" => "mixed", "price" => "float|int", "couponMoney" => "float|int"])] public function getTradeAmount(?User $user, ?UserGroup $userGroup, int $cardId, int $num, string $coupon, int|Commodity|null $commodityId, ?string $race = null, bool $disableShared = false): array
    {
        if ($num <= 0) {
            throw new JSONException("购买数量不能低于1个");
        }

        if ($commodityId instanceof Commodity) {
            $commodity = $commodityId;
        } else {
            $commodity = Commodity::query()->find($commodityId);
        }

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }
        if ($commodity->status != 1) {
            throw new JSONException("当前商品已停售");
        }

        $data = [];

        if ($commodity->delivery_way == 0 && ($commodity->shared_id == null || $commodity->shared_id == 0)) {
            if ($race) {
                $data['card_count'] = Card::query()->where("commodity_id", $commodity->id)->where("status", 0)->where("race", $race)->count();
            }
        } elseif ($commodity->shared_id != 0) {
            //查远程平台的库存
            $shared = \App\Model\Shared::query()->find($commodity->shared_id);
            if ($shared && !$disableShared) {
                $inventory = $this->shared->inventory($shared, $commodity->shared_code, (string)$race);
                $data['card_count'] = $inventory['count'];
            }
        }

        //检测限购数量
        if ($commodity->minimum != 0 && $num < $commodity->minimum) {
            throw new JSONException("本商品单次最少购买{$commodity->minimum}个");
        }

        if ($commodity->maximum != 0 && $num > $commodity->maximum) {
            throw new JSONException("本商品单次最多购买{$commodity->maximum}个");
        }

        if ($cardId != 0 && $commodity->draft_status == 1) {
            $num = 1;
        }

        $ow = 0;
        if ($user) {
            $ow = $user->id;
        }
        $amount = $this->calcAmount($ow, $num, $commodity, $userGroup, $race);
        if ($cardId != 0 && $commodity->draft_status == 1) {
            $amount = $amount + $commodity->draft_premium;
        }

        $couponMoney = 0;
        //优惠卷
        $price = $amount / $num;
        if ($coupon != "") {
            $voucher = Coupon::query()->where("code", $coupon)->first();

            if (!$voucher) {
                throw new JSONException("该优惠卷不存在");
            }

            if ($voucher->owner != $commodity->owner) {
                throw new JSONException("该优惠卷不存在");
            }

            if ($race && $voucher->commodity_id != 0) {
                if ($race != $voucher->race) {
                    throw new JSONException("该优惠卷不能抵扣当前商品");
                }
            }

            if ($voucher->commodity_id != 0 && $voucher->commodity_id != $commodity->id) {
                throw new JSONException("该优惠卷不属于该商品");
            }

            //判断该优惠卷是否有分类设定
            if ($voucher->commodity_id == 0 && $voucher->category_id != 0 && $voucher->category_id != $commodity->category_id) {
                throw new JSONException("该优惠卷不能抵扣当前商品");
            }

            if ($voucher->status != 0) {
                throw new JSONException("该优惠卷已失效");
            }

            //检测过期时间
            if ($voucher->expire_time != null && strtotime($voucher->expire_time) < time()) {
                throw new JSONException("该优惠卷已过期");
            }

            //检测面额
            if ($voucher->money >= $amount) {
                throw new JSONException("该优惠卷面额大于订单金额");
            }

            $deduction = $voucher->mode == 0 ? $voucher->money : $price * $voucher->money;
            $amount = $amount - $deduction;
            $couponMoney = $deduction;
        }


        $data ['amount'] = sprintf("%.2f", (int)(string)($amount * 100) / 100);
        $data ['price'] = sprintf("%.2f", (int)(string)($price * 100) / 100);
        $data ['couponMoney'] = sprintf("%.2f", (int)(string)($couponMoney * 100) / 100);

        return $data;
    }


    /**
     * @param Commodity $commodity
     * @param string $race
     * @param int $num
     * @param string $contact
     * @param string $password
     * @param int|null $cardId
     * @param int $userId
     * @param string $widget
     * @return array
     * @throws JSONException
     */
    public function giftOrder(Commodity $commodity, string $race = "", int $num = 1, string $contact = "", string $password = "", ?int $cardId = null, int $userId = 0, string $widget = "[]"): array
    {
        return DB::transaction(function () use ($race, $widget, $contact, $password, $num, $cardId, $commodity, $userId) {
            //创建订单
            $date = Date::current();
            $order = new  \App\Model\Order();
            $order->owner = $userId;
            $order->trade_no = Str::generateTradeNo();
            $order->amount = 0;
            $order->commodity_id = $commodity->id;
            $order->card_id = $cardId;
            $order->card_num = $num;
            $order->pay_id = 1;
            $order->create_time = $date;
            $order->create_ip = Client::getAddress();
            $order->create_device = 0;
            $order->status = 0;
            $order->password = $password;
            $order->contact = trim($contact);
            $order->delivery_status = 0;
            $order->widget = $widget;
            $order->rent = 0;
            $order->race = $race;
            $order->user_id = $commodity->owner;
            $order->save();
            $secret = $this->orderSuccess($order);
            return [
                "secret" => $secret,
                "tradeNo" => $order->trade_no
            ];
        });
    }
}