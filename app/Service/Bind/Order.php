<?php
declare(strict_types=1);

namespace App\Service\Bind;

use App\Consts\Hook;
use App\Entity\PayEntity;
use App\Model\Bill;
use App\Model\Business;
use App\Model\BusinessLevel;
use App\Model\Card;
use App\Model\Commodity;
use App\Model\CommodityGroup;
use App\Model\Config;
use App\Model\Coupon;
use App\Model\OrderOption;
use App\Model\Pay;
use App\Model\User;
use App\Model\UserCommodity;
use App\Model\UserGroup;
use App\Service\Email;
use App\Service\Shared;
use App\Util\Client;
use App\Util\Currency;
use App\Util\Date;
use App\Util\Ini;
use App\Util\PayConfig;
use App\Util\PayFactory;
use App\Util\PayProfile;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Inject;
use Kernel\Container\Di;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Util\Arr;
use Kernel\Util\Context;
use Kernel\Util\Decimal;
use Kernel\Waf\Firewall;

class Order implements \App\Service\Order
{
    private const ORDER_COMMODITY_SNAPSHOT_FIELDS = [
        'category_id',
        'factory_price',
        'price',
        'user_price',
        'delivery_way',
        'delivery_auto_mode',
        'delivery_message',
        'contact_type',
        'password_status',
        'coupon',
        'shared_id',
        'shared_code',
        'shared_premium',
        'shared_premium_type',
        'draft_status',
        'draft_premium',
        'widget',
        'level_price',
        'level_disable',
        'config',
    ];

    public const CALLBACK_REJECT = "fail";

    #[Inject]
    private Shared $shared;

    #[Inject]
    private Email $email;

    public function calcAmount(int $owner, int $num, Commodity $commodity, ?UserGroup $group, ?string $race = null, bool $disableSubstation = false, ?array $sku = []): float
    {
        $premium = 0;

        $bus = Business::get(Client::getDomain());
        if ($bus && !$disableSubstation) {
            if ($userCommodity = UserCommodity::getCustom($bus->user_id, $commodity->id)) {
                $premium = (float)$userCommodity->premium;
            }
        }

        $commodity = clone $commodity;

        $userDefinedConfig = Commodity::parseGroupConfig((string)$commodity->level_price, $group);

        $this->parseConfig($commodity, $group);
        $config = (array)$commodity->config;

        $price = $owner == 0 ? $commodity->price : $commodity->user_price;

        if (!empty($race) && isset($config['category'][$race])) {
            $price = (float)$config['category'][$race];
        }

        if ($commodity->level_disable == 1) {
            return (int)(string)(($num * ($price + $premium)) * 100) / 100;
        }

        if ($userDefinedConfig && $userDefinedConfig['amount'] > 0) {
            if (empty($config['category'])) {
                $price = $userDefinedConfig['amount'];
            }
        } elseif ($group) {
            $price = $price - ($price * $group->discount);
        }

        if (!empty($config['category'])) {
            if (!empty($race) && isset($config['category_wholesale'][$race]) && is_array($config['category_wholesale'][$race])) {
                $list = $config['category_wholesale'][$race];
                krsort($list);
                foreach ($list as $k => $v) {
                    if ($num >= $k) {
                        $price = $v;
                        break;
                    }
                }
            }
        } else {
            $list = (array)($config['wholesale'] ?? []);
            krsort($list);
            foreach ($list as $k => $v) {
                if ($num >= $k) {
                    $price = $v;
                    break;
                }
            }
        }

        if (!empty($sku) && !empty($config['sku']) && is_array($config['sku'])) {
            foreach ($sku as $k => $v) {
                $skuPremium = $config['sku'][$k][$v] ?? 0;
                if (is_numeric($skuPremium) && $skuPremium > 0) {
                    $price += $skuPremium;
                }
            }
        }

        $price += $premium;
        return (int)(string)(($num * $price) * 100) / 100;
    }

    public function valuation(Commodity|int $commodity, int $num = 1, ?string $race = null, ?array $sku = [], ?int $cardId = null, ?string $coupon = null, ?UserGroup $group = null): string
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }

        if (!$commodity) {
            throw new JSONException("商品不存在#1");
        }

        $commodity = clone $commodity;
        $price = (new Decimal($group ? $commodity->user_price : $commodity->price, 2));

        $levelPrice = $this->userDefinedPrice($commodity, $group);
        if ($levelPrice && $levelPrice['amount'] > 0 && $levelPrice['amount'] < $price->getAmount()) {
            $price = new Decimal($levelPrice['amount'], 2);
        }

        $this->parseConfig($commodity, $group);

        if (!empty($race) && !empty($commodity->config['category'])) {
            $_race = $commodity->config['category'];

            if (!isset($_race[$race])) {
                throw new JSONException("此商品类型不存在[" . $this->echoSafe($race) . "]");
            }

            $price = (new Decimal($_race[$race], 2));
            if (is_array($commodity->config['category_wholesale'])) {
                if (array_key_exists($race, $commodity->config['category_wholesale'])) {
                    $list = $commodity->config['category_wholesale'][$race];
                    krsort($list);
                    foreach ($list as $k => $v) {
                        if ($num >= $k) {
                            $price = (new Decimal($v, 2));
                            break;
                        }
                    }
                }

            }

        } else {
            if (is_array($commodity->config['wholesale'])) {
                $list = $commodity->config['wholesale'];
                krsort($list);
                foreach ($list as $k => $v) {
                    if ($num >= $k) {
                        $price = (new Decimal($v, 2));
                        break;
                    }
                }
            }
        }

        if (!empty($sku) && !empty($commodity->config['sku'])) {
            $_sku = $commodity->config['sku'];

            foreach ($sku as $k => $v) {
                if (!isset($_sku[$k])) {
                    throw new JSONException("此SKU不存在[" . $this->echoSafe($k) . "]");
                }

                if (!isset($_sku[$k][$v])) {
                    throw new JSONException("此SKU不存在[" . $this->echoSafe($v) . "]");
                }

                $_sku_price = $_sku[$k][$v] ?: 0;

                if (is_numeric($_sku_price) && $_sku_price > 0) {
                    $price = $price->add($_sku_price);
                }
            }
        }

        if (!empty($cardId) && $commodity->draft_status == 1 && $num == 1) {
            $shop = Di::inst()->make(\App\Service\Shop::class);

            if ($commodity->shared) {
                $draft = $this->shared->getDraft($commodity->shared, $commodity->shared_code, $cardId);
                $draftPremium = $draft['draft_premium'] > 0 ? $this->shared->AdjustmentExtra($commodity, $draft['draft_premium']) : 0;
            } else {
                $draft = $shop->getDraft($commodity, $cardId);
                $draftPremium = $draft['draft_premium'];
            }

            if ($draftPremium > 0) {
                $price = $price->add($draftPremium);
            } else {
                $price = $price->add($commodity->draft_premium);
            }
        }

        if ($commodity->level_disable == 1) {
            return $price->mul($num)->getAmount();
        }

        if ($group && is_array($group->discount_config)) {
            $discountConfig = $group->discount_config;
            asort($discountConfig);
            $commodityGroups = CommodityGroup::query()->whereIn("id", array_keys($discountConfig))->get();

            foreach ($commodityGroups as $commodityGroup) {
                if (is_array($commodityGroup->commodity_list) && in_array($commodity->id, $commodityGroup->commodity_list)) {
                    $price = $price->mul((new Decimal($discountConfig[$commodityGroup->id], 3))->div(100)->getAmount());
                    break;
                }
            }
        }

        if (!empty($coupon) && $num == 1) {
            $voucher = Coupon::query()->where("code", $coupon)->first();

            if (!$voucher) {
                throw new JSONException("该优惠券不存在");
            }

            if ($voucher->owner != $commodity->owner) {
                throw new JSONException("该优惠券不存在");
            }

            if ($voucher->commodity_id != 0 && $voucher->commodity_id != $commodity->id) {
                throw new JSONException("该优惠券不属于该商品");
            }

            if ($voucher->race && $voucher->commodity_id != 0 && $race != $voucher->race) {
                throw new JSONException("该优惠券不能抵扣当前商品");
            }

            if ($voucher->sku && is_array($voucher->sku) && $voucher->commodity_id != 0) {
                if (!is_array($sku)) {
                    throw new JSONException("此优惠券不适用当前商品");
                }

                foreach ($voucher->sku as $key => $sk) {
                    if (!isset($sku[$key])) {
                        throw new JSONException("此优惠券不适用此SKU");
                    }

                    if ($sk != $sku[$key]) {
                        throw new JSONException("此优惠券不适用此SKU{$sku[$key]}");
                    }
                }
            }

            if ($voucher->commodity_id == 0 && $voucher->category_id != 0 && $voucher->category_id != $commodity->category_id) {
                throw new JSONException("该优惠券不能抵扣当前商品");
            }

            if ($voucher->status != 0) {
                throw new JSONException("该优惠券已失效");
            }

            if ($voucher->expire_time != null && strtotime($voucher->expire_time) < time()) {
                throw new JSONException("该优惠券已过期");
            }

            if ($voucher->mode == 0 && $voucher->money >= $price->getAmount()) {
                return "0";
            }

            $deduction = $voucher->mode == 0 ? $voucher->money : $price->mul($voucher->money)->getAmount();
            $price = $price->sub($deduction);
        }

        return $price->mul($num)->getAmount();
    }

    public function getCost(Commodity|int $commodity, int $num = 1, ?string $race = null, ?array $sku = [], ?int $cardId = null): string
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        $commodity = clone $commodity;

        $price = (new Decimal($commodity->factory_price, 2));

        $config = Ini::toArray($commodity->config ?: "") ?: [];

        if (!empty($race) && !empty($config['category_cost'])) {
            $_race = $config['category_cost'];
            if (isset($_race[$race])) {
                $price = (new Decimal($_race[$race], 2));
            } else {
                $price = (new Decimal(0, 2));
            }
        }

        if (!empty($sku) && !empty($config['sku_cost'])) {
            $_sku = $config['sku_cost'];
            foreach ($sku as $k => $v) {
                if (isset($_sku[$k][$v])) {
                    $_sku_price = $_sku[$k][$v] ?: 0;
                    if (is_numeric($_sku_price) && $_sku_price > 0) {
                        $price = $price->add($_sku_price);
                    }
                }
            }
        }

        if (!empty($cardId) && $commodity->draft_status == 1 && $num == 1) {
            $shop = Di::inst()->make(\App\Service\Shop::class);

            if ($commodity->shared) {
                $draft = $this->shared->getDraft($commodity->shared, $commodity->shared_code, $cardId);
                $draftPremium = $draft['draft_premium'];
            } else {
                $draft = $shop->getDraft($commodity, $cardId);
                $draftPremium = $draft['cost'];
            }

            if ($draftPremium > 0) {
                $price = $price->add($draftPremium);
            }
        }

        return $price->mul($num)->getAmount();
    }

    public function getValuationPrice(int $commodityId, string|float|int $price, ?UserGroup $group = null): string
    {
        $price = new Decimal($price);

        if ($group && is_array($group->discount_config)) {
            $discountConfig = $group->discount_config;
            asort($discountConfig);
            $commodityGroups = CommodityGroup::query()->whereIn("id", array_keys($discountConfig))->get();

            foreach ($commodityGroups as $commodityGroup) {
                if (is_array($commodityGroup->commodity_list) && in_array($commodityId, $commodityGroup->commodity_list)) {
                    $price = $price->mul((new Decimal($discountConfig[$commodityGroup->id], 3))->div(100)->getAmount());
                    break;
                }
            }
        }

        return $price->getAmount();
    }

    public function parseConfig(Commodity &$commodity, ?UserGroup $group): void
    {
        $parseConfig = Ini::toArray((string)$commodity->config);

        $userDefinedConfig = Commodity::parseGroupConfig($commodity->level_price, $group);

        if ($userDefinedConfig) {
            if (key_exists("category", $userDefinedConfig['config'])) {
                $parseConfig['category'] = Arr::override($userDefinedConfig['config']['category'] ?? null, $parseConfig['category'] ?? null);
            }

            if (key_exists("wholesale", $userDefinedConfig['config'])) {
                $parseConfig['wholesale'] = Arr::override($userDefinedConfig['config']['wholesale'] ?? null, $parseConfig['wholesale'] ?? null);
            }

            if (key_exists("category_wholesale", $userDefinedConfig['config'])) {
                $parseConfig['category_wholesale'] = Arr::override($userDefinedConfig['config']['category_wholesale'] ?? null, $parseConfig['category_wholesale'] ?? null);
            }

            if (key_exists("sku", $userDefinedConfig['config'])) {
                $parseConfig['sku'] = Arr::override($userDefinedConfig['config']['sku'] ?? null, $parseConfig['sku'] ?? null);
            }
        }

        $commodity->config = $parseConfig;
        $commodity->level_price = null;
    }

    public function userDefinedPrice(Commodity $commodity, ?UserGroup $group): ?array
    {
        if ($group) {
            $levelPrice = (array)json_decode((string)$commodity->level_price, true);
            return array_key_exists($group->id, $levelPrice) ? $levelPrice[$group->id] : null;
        }
        return null;
    }

    private function lockCommodityForOrder(Commodity $expected): Commodity
    {
        $locked = Commodity::query()
            ->whereKey((int)$expected->id)
            ->lockForUpdate()
            ->first();

        if (!$locked) {
            throw new JSONException('商品不存在或已被删除，请刷新后重试');
        }
        if ((int)$locked->owner !== (int)$expected->owner) {
            throw new JSONException('商品归属已经变更，请刷新后重试');
        }

        $locked->load('shared');
        return $locked;
    }

    private function assertTradeCommoditySnapshot(Commodity $expected, Commodity $locked): void
    {
        foreach (self::ORDER_COMMODITY_SNAPSHOT_FIELDS as $field) {
            if ((string)$expected->getRawOriginal($field) !== (string)$locked->getRawOriginal($field)) {
                throw new JSONException('商品信息已经更新，请刷新后重新下单');
            }
        }
    }

    private function lockLocalDraftCardForOrder(Commodity $commodity, int $cardId): void
    {
        if ($cardId <= 0 || (int)$commodity->draft_status !== 1 || (int)$commodity->shared_id > 0) {
            return;
        }

        $card = Card::query()
            ->whereKey($cardId)
            ->lockForUpdate()
            ->first(['id', 'commodity_id', 'status']);
        if (!$card) {
            throw new JSONException('预选的宝贝不存在');
        }
        if ((int)$card->commodity_id !== (int)$commodity->id) {
            throw new JSONException('此预选卡密不属于当前商品');
        }
        if ((int)$card->status !== 0) {
            throw new JSONException('此宝贝已被他人抢走');
        }
    }

    public function trade(?User $user, ?UserGroup $userGroup, array $map): array
    {
        $commodityId = (int)$map['item_id'];
        $contact = (string)$map['contact'];
        $num = (int)$map['num'];
        $cardId = (int)$map['card_id'];
        $payId = (int)$map['pay_id'];
        $device = (int)$map['device'];
        $password = (string)$map['password'];
        $coupon = (string)$map['coupon'];
        $from = $_COOKIE['promotion_from'] ?? 0;
        $owner = $user == null ? 0 : $user->id;
        $race = (string)$map['race'];
        $requestNo = (string)$map['request_no'];
        $sku = $map['sku'] ?: null;

        if ($user && $user->pid > 0) {
            $from = $user->pid;
        }

        if ($commodityId == 0) {
            throw new JSONException("请选择商品");
        }

        if ($num <= 0) {
            throw new JSONException("至少购买1个");
        }

        $commodity = Commodity::with(['shared'])->find($commodityId);

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        if ($commodity->status != 1) {
            throw new JSONException("当前商品已停售");
        }

        if (Config::get("force_login") == 1 || $commodity->only_user == 1 || $commodity->purchase_count > 0) {
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

        if ($commodity->widget) {
            $widgetList = (array)json_decode((string)$commodity->widget, true);
            foreach ($widgetList as $item) {
                if (($item['type'] ?? '') === 'custom') {
                    continue;
                }
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

        ($commodity->draft_status == 1 && $cardId != 0) && $num = 1;

        $regx = ['/^1[3456789]\d{9}$/', '/.*(.{2}@.*)$/i', '/[1-9]{1}[0-9]{4,11}/'];
        $msg = ['手机', '邮箱', 'QQ号'];

        $shopService = Di::inst()->make(\App\Service\Shop::class);

        if (!$user) {
            if (mb_strlen($contact) < 3) {
                throw new JSONException("联系方式不能低于3个字符");
            }

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

        $configCommodity = clone $commodity;
        $this->parseConfig($configCommodity, $userGroup);
        $commodityConfig = is_array($configCommodity->config) ? $configCommodity->config : [];

        if (!empty($commodityConfig['category']) && is_array($commodityConfig['category'])) {
            if ($race === '') {
                throw new JSONException("请选择商品类型");
            }
            if (!array_key_exists($race, $commodityConfig['category'])) {
                throw new JSONException("此商品类型不存在[" . $this->echoSafe($race) . "]");
            }
        }

        if (!empty($commodityConfig['sku']) && is_array($commodityConfig['sku'])) {
            foreach ($commodityConfig['sku'] as $skuName => $skuOptions) {
                if (!is_array($skuOptions) || $skuOptions === []) {
                    continue;
                }
                if (!is_array($sku) || !isset($sku[$skuName]) || (string)$sku[$skuName] === '') {
                    throw new JSONException("请选择{$skuName}");
                }
                if (!array_key_exists((string)$sku[$skuName], $skuOptions)) {
                    throw new JSONException("{$skuName}选择错误");
                }
            }
        }

        $rent = 0;

        if ($commodity->shared) {
            $stock = $this->shared->getItemStock((clone $commodity), $commodity->shared, $commodity->shared_code, $race ?: null, $sku ?: []);

            $rent = $this->shared->getValuation((clone $commodity), $commodity->shared, $commodity->shared_code, $num, $race, $sku, $cardId);
        } else {
            $stock = $shopService->getItemStock($commodity, $race, $sku);
        }

        if (($stock == 0 || $num > $stock)) {
            throw new JSONException("库存不足");
        }

        if ($commodity->purchase_count > 0 && $owner > 0) {
            $orderCount = \App\Model\Order::query()->where("owner", $owner)->where("commodity_id", $commodity->id)->count();
            if ($orderCount >= $commodity->purchase_count) {
                throw new JSONException("该商品每人只能购买{$commodity->purchase_count}件");
            }
        }

        $amount = $this->valuation($commodity, $num, $race, $sku, $cardId, $coupon, $userGroup);
        $rent == 0 && $rent = $this->getCost($commodity, $num, $race, $sku, $cardId);
        $rebate = 0;
        $divideAmount = 0;

        $business = Business::get();
        if ($business) {
            $_user = User::query()->find($business->user_id);
            if ($commodity->owner === $business->user_id) {
                $_level = BusinessLevel::query()->find($_user->business_level);
                $rebate = (new Decimal($amount))->sub((new Decimal($amount))->mul($_level->cost)->getAmount())->getAmount();
            } else {
                $amount = $shopService->getSubstationPrice($commodity, $amount);
                $_userGroup = UserGroup::get($_user->recharge);

                $rebate = (new Decimal($amount))->sub($this->valuation($commodity, $num, $race, $sku, $cardId, $coupon, $_userGroup))->getAmount();
            }
        } else {
            if ($commodity->owner > 0) {
                $_user = User::query()->find($commodity->owner);
                $_level = BusinessLevel::query()->find($_user->business_level);
                $rebate = (new Decimal($amount))->sub((new Decimal($amount))->mul($_level->cost)->getAmount())->getAmount();
            }
        }

        if ($from > 0 && $commodity->owner != $from && $owner != $from && (!$business || $business->user_id != $from)) {
            $x_user = User::query()->find($from);
            $x_userGroup = UserGroup::get($x_user->recharge);

            $x_amount = $this->valuation($commodity, $num, $race, $sku, $cardId, $coupon, $x_userGroup);

            if ($rebate > 0) {
                $x_amount = $shopService->getSubstationPrice($commodity, $x_amount);

                $x_divideAmount = (new Decimal($amount))->sub($x_amount)->getAmount();
                if ($rebate > $x_divideAmount) {
                    $rebate = (new Decimal($rebate))->sub($x_divideAmount)->getAmount();
                    $divideAmount = $x_divideAmount;
                }
            } else {
                $divideAmount = (new Decimal($amount))->sub($x_amount)->getAmount();
            }
        } else {
            $from = 0;
        }

        $pay = Pay::query()->find($payId);

        if (!$pay) {
            throw new JSONException("该支付方式不存在");
        }

        if ($pay->commodity != 1) {
            throw new JSONException("当前支付方式已停用，请换个支付方式再进行支付");
        }

        $callbackDomain = trim(Config::get("callback_domain"), "/");
        $clientDomain = Client::getUrl();

        if (!$callbackDomain) {
            $callbackDomain = $clientDomain;
        }

        DB::connection()->getPdo()->exec("set session transaction isolation level serializable");
        $result = Db::transaction(function () use ($commodity, $rent, $rebate, $divideAmount, $business, $sku, $requestNo, $user, $userGroup, $num, $contact, $device, $amount, $owner, $pay, $cardId, $password, $coupon, $from, $widget, $race, $callbackDomain, $clientDomain) {
            $lockedCommodity = $this->lockCommodityForOrder($commodity);

            if ((int)$lockedCommodity->status !== 1) {
                throw new JSONException('当前商品已停售');
            }
            $this->assertTradeCommoditySnapshot($commodity, $lockedCommodity);
            $this->lockLocalDraftCardForOrder($lockedCommodity, $cardId);

            if (((int)$lockedCommodity->only_user === 1 || (int)$lockedCommodity->purchase_count > 0) && $owner === 0) {
                throw new JSONException('请先登录后再购买哦');
            }
            if ((int)$lockedCommodity->minimum > 0 && $num < (int)$lockedCommodity->minimum) {
                throw new JSONException("本商品最少购买{$lockedCommodity->minimum}个");
            }
            if ((int)$lockedCommodity->maximum > 0 && $num > (int)$lockedCommodity->maximum) {
                throw new JSONException("本商品单次最多购买{$lockedCommodity->maximum}个");
            }
            if ((int)$lockedCommodity->seckill_status === 1) {
                if (time() < strtotime((string)$lockedCommodity->seckill_start_time)) {
                    throw new JSONException('抢购还未开始');
                }
                if (time() > strtotime((string)$lockedCommodity->seckill_end_time)) {
                    throw new JSONException('抢购已结束');
                }
            }
            if ((int)$lockedCommodity->purchase_count > 0 && $owner > 0) {
                $orderCount = \App\Model\Order::query()
                    ->where('owner', $owner)
                    ->where('commodity_id', $lockedCommodity->id)
                    ->count();
                if ($orderCount >= (int)$lockedCommodity->purchase_count) {
                    throw new JSONException("该商品每人只能购买{$lockedCommodity->purchase_count}件");
                }
            }

            if ($user) {
                $contact = Str::generateRandStr(16);
            }

            if ($requestNo && \App\Model\Order::query()->where("request_no", $requestNo)->first()) {
                throw new JSONException("The request ID already exists");
            }

            $date = Date::current();
            $order = new  \App\Model\Order();
            $order->widget = $widget;

            $order->leave_message = $lockedCommodity->leave_message;
            $order->owner = $owner;
            $order->trade_no = Str::generateTradeNo();
            $order->amount = (new Decimal($amount, 2))->getAmount();
            $order->commodity_id = $lockedCommodity->id;
            $order->pay_id = $pay->id;
            $order->create_time = $date;
            $order->create_ip = Client::getAddress();
            $order->create_device = $device;
            $order->status = 0;
            $order->contact = trim((string)$contact);
            $order->delivery_status = 0;
            $order->card_num = $num;
            $order->user_id = (int)$lockedCommodity->owner;
            $order->rent = $rent;

            if ($requestNo) $order->request_no = $requestNo;
            if (!empty($race)) $order->race = $race;
            if (!empty($sku)) $order->sku = $sku;
            if ($lockedCommodity->draft_status == 1 && $cardId != 0) $order->card_id = $cardId;
            if ($password != "") $order->password = $password;
            if ($business) $order->substation_user_id = $business->user_id;
            if ($rebate > 0) $order->rebate = $rebate;
            if ($from > 0) $order->from = $from;
            if ($divideAmount > 0) $order->divide_amount = $divideAmount;

            if (!empty($coupon)) {
                $voucher = Coupon::query()->where("code", $coupon)->lockForUpdate()->first();
                if (!$voucher) {
                    throw new JSONException("优惠券不存");
                }
                if ($voucher->status != 0) {
                    throw new JSONException("该优惠券已失效");
                }
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

            hook(Hook::USER_API_ORDER_TRADE_PAY_BEGIN, $lockedCommodity, $order, $pay);

            $url = "";
            if ((float)$order->amount <= 0) {
                $order->amount = "0.00";
                $order->save();
                $secret = $this->orderSuccess($order);

                $url = $owner == 0
                    ? $clientDomain . '/user/index/query?tradeNo=' . $order->trade_no
                    : $clientDomain . '/user/personal/purchaseRecord?tradeNo=' . $order->trade_no;
            } else {
                if ($pay->handle == "#system") {
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

                    Bill::create($session, $order->amount, Bill::TYPE_SUB, "商品下单[{$order->trade_no}]");

                    $order->save();
                    $secret = $this->orderSuccess($order);

                    $url = $clientDomain . '/user/personal/purchaseRecord?tradeNo=' . $order->trade_no;
                } else {
                    $order->pay_cost = $pay->cost_type == 0 ? $pay->cost : (new Decimal($order->amount, 2))->mul($pay->cost)->getAmount();
                    $order->amount = (new Decimal($order->amount, 2))->add($order->pay_cost)->getAmount();

                    if ($owner == 0) {
                        $returnUrl = $clientDomain . '/user/index/query?tradeNo=' . $order->trade_no;
                    } else {
                        $returnUrl = $clientDomain . '/user/personal/purchaseRecord?tradeNo=' . $order->trade_no;
                    }

                    $order->gateway_amount = Currency::toCny($order->amount);

                    $payObject = PayFactory::make(
                        $pay,
                        (string)$order->trade_no,
                        (float)$order->gateway_amount,
                        $callbackDomain . '/user/api/order/callback.' . $order->trade_no,
                        $returnUrl,
                        Client::getAddress()
                    );

                    $trade = $payObject->trade();
                    if ($trade instanceof PayEntity) {
                        $order->pay_url = $trade->getUrl();
                        switch ($trade->getType()) {
                            case \App\Pay\Pay::TYPE_REDIRECT:
                                $url = $order->pay_url;
                                break;
                            case \App\Pay\Pay::TYPE_LOCAL_RENDER:
                                $url = '/user/pay/order.' . $order->trade_no . ".1";
                                break;
                            case \App\Pay\Pay::TYPE_SUBMIT:
                                $url = '/user/pay/order.' . $order->trade_no . ".2";
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

            hook(Hook::USER_API_ORDER_TRADE_AFTER, $lockedCommodity, $order, $pay);

            return ['url' => $url, 'amount' => $order->amount, 'tradeNo' => $order->trade_no, 'secret' => $secret, 'leave_message' => \App\Model\Order::resolveLeaveMessage($order->leave_message, null)];
        });
        $result["stock"] = $shopService->getItemStock($commodity, $race, $sku);
        return $result;
    }

    public static function callbackFail(string $handle, string $reason, string $error, ?string $tradeNo, array $map, ?string $logMessage = null, string $logType = "CALLBACK"): void
    {
        if ($logMessage !== null && $handle !== '' && Str::isValid($handle) && PayConfig::isValid($handle)) {
            PayConfig::log($handle, $logType, $logMessage);
        }
        try {
            hook(Hook::SERVICE_PAY_CALLBACK_FAIL, $handle, $reason, $tradeNo, $map);
        } catch (\Throwable $e) {
        }
        throw new JSONException($error);
    }

    public function callbackInitialize(\App\Model\Pay $pay, array $map, ?array $payConfig = null): array
    {
        $handle = (string)$pay->handle;
        $payInfo = PayConfig::info($handle);

        if (!is_array($payInfo) || !is_array($payInfo['callback'] ?? null)) {
            self::callbackFail($handle, "plugin", self::CALLBACK_REJECT, null, $map, "插件缺少 Config/Info.php 的 callback 定义");
        }

        $payConfig = $payConfig ?? PayConfig::config($handle);
        $callback = $payInfo['callback'];
        $tradeNo = (string)($map[$callback[\App\Consts\Pay::FIELD_ORDER_KEY] ?? ''] ?? '') ?: null;

        $autoload = BASE_PATH . '/app/Pay/' . $handle . "/Vendor/autoload.php";
        if (file_exists($autoload)) {
            require($autoload);
        }

        if ($callback[\App\Consts\Pay::IS_SIGN]) {
            if (!self::payCredentialConfigured($payConfig)) {
                self::callbackFail($handle, "credential", self::CALLBACK_REJECT, $tradeNo, $map, "支付凭据未配置，拒绝回调");
            }
            $class = "\\App\\Pay\\{$handle}\\Impl\\Signature";
            if (!class_exists($class)) {
                self::callbackFail($handle, "plugin", self::CALLBACK_REJECT, $tradeNo, $map, "插件未实现接口");
            }
            $signature = new $class;
            Context::set(\App\Consts\Pay::DAFA, $map);
            if (!$signature->verification($map, $payConfig)) {
                self::callbackFail($handle, "sign", self::CALLBACK_REJECT, $tradeNo, $map, "签名验证失败");
            }

            $map = Context::get(\App\Consts\Pay::DAFA);
        }

        if ($callback[\App\Consts\Pay::IS_STATUS]) {
            if ((string)($map[$callback[\App\Consts\Pay::FIELD_STATUS_KEY]] ?? '') !== (string)$callback[\App\Consts\Pay::FIELD_STATUS_VALUE]) {
                self::callbackFail($handle, "status", self::CALLBACK_REJECT, $tradeNo, $map, "状态验证失败");
            }
        }

        return [
            "trade_no" => (string)($map[$callback[\App\Consts\Pay::FIELD_ORDER_KEY]] ?? ''),
            "amount" => $map[$callback[\App\Consts\Pay::FIELD_AMOUNT_KEY]] ?? null,
            "success" => $callback[\App\Consts\Pay::FIELD_RESPONSE]
        ];
    }

    private static function payCredentialConfigured(?array $config): bool
    {
        if (empty($config)) {
            return false;
        }
        $pattern = '/(secret|token|private_?key|public_?key|app_?secret|api_?key|mch_?key|md5_?key|(^|_)key$)/i';
        $found = false;
        foreach ($config as $k => $v) {
            if (is_string($k) && preg_match($pattern, $k)) {
                $found = true;
                if (is_string($v) && trim($v) !== '') {
                    return true;
                }
            }
        }
        return !$found;
    }

    public static function isCallbackTradeNo(string $param): bool
    {
        return $param !== '' && preg_match('/^\d+$/D', $param) === 1;
    }

    public function orderSuccess(\App\Model\Order $order): string
    {
        $commodity = $order->commodity;
        $order->pay_time = Date::current();
        $order->status = 1;
        $shared = $commodity->shared;

        if ($shared) {
            $order->secret = $this->shared->trade($shared, $commodity, $order->contact, $order->card_num, (int)$order->card_id, $order->create_device, (string)$order->password, (string)$order->race, $order->sku ?: [], $order->widget, $order->trade_no);
            $order->delivery_status = 1;
        } else {
            if ($commodity->delivery_way == 0) {
                $order->secret = $this->pullCardForLocal($order, $commodity);
                $order->delivery_status = 1;
            } else {
                $order->secret = ($commodity->delivery_message != null && $commodity->delivery_message != "") ? $commodity->delivery_message : '正在发货中，请耐心等待，如有疑问，请联系客服。';

                if ($commodity->stock >= $order->card_num) {
                    Commodity::query()->where("id", $commodity->id)->decrement('stock', $order->card_num);
                } else {
                    Commodity::query()->where("id", $commodity->id)->update(['stock' => 0]);
                }
            }
        }

        if ($order->from > 0 && $order->divide_amount > 0) {
            Bill::create($order->from, $order->divide_amount, Bill::TYPE_ADD, "推广分成[$order->trade_no]", 1);
        }

        if ($order->rebate > 0) {
            if ($order->user_id > 0) {
                Bill::create($order->user_id, $order->rebate, Bill::TYPE_ADD, "自营商品出售[$order->trade_no]", 1);
            } elseif ($order->substation_user_id > 0) {
                Bill::create($order->substation_user_id, $order->rebate, Bill::TYPE_ADD, "分站商品出售[$order->trade_no]", 1);
            }
        }

        $order->save();

        if ($commodity->contact_type == 2 && $commodity->send_email == 1 && $order->owner == 0) {
            try {
                $this->email->send($order->contact, "【发货提醒】您购买的卡密发货啦", "您购买的卡密如下：" . $order->secret);
            } catch (\Exception|\Error $e) {
            }
        }

        hook(Hook::USER_API_ORDER_PAY_AFTER, $commodity, $order, $order->pay);

        return (string)$order->secret;
    }

    private function pullCardForLocal(\App\Model\Order $order, Commodity $commodity): string
    {
        $secret = "很抱歉，有人在你付款之前抢走了商品，请联系客服。";

        $draft = $order->card;

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

        $direction = match ($commodity->delivery_auto_mode) {
            0 => "id asc",
            1 => "rand()",
            2 => "id desc"
        };
        $cards = Card::query()->where("commodity_id", $order->commodity_id)->orderByRaw($direction)->where("status", 0);

        if ($order->race) {
            $cards = $cards->where("race", $order->race);
        } else {
            $cards = $cards->where(function ($query) {
                $query->whereNull("race")->orWhere("race", "");
            });
        }

        if (!empty($order->sku)) {
            foreach ($order->sku as $k => $v) {
                $cards = $cards->where("sku->{$k}", $v);
            }
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
                $rows = Card::query()->whereIn("id", $ids)->update(['purchase_time' => $order->pay_time, 'order_id' => $order->id, 'status' => 1]);
                if ($rows != 0) {
                    $secret = trim($cardc, PHP_EOL);
                }
            } catch (\Exception $e) {
            }
        }

        return $secret;
    }

    public function callback(string $tradeNo, array $map): string
    {
        $tradeNo = Firewall::inst()->xssKiller($tradeNo);

        if (!self::isCallbackTradeNo($tradeNo)) {
            self::callbackFail('', "handle", self::CALLBACK_REJECT, null, $map);
        }

        $order = \App\Model\Order::with(['pay'])->where("trade_no", $tradeNo)->first();

        if (!$order || !$order->pay) {
            self::callbackFail('', "not_found", self::CALLBACK_REJECT, $tradeNo, $map);
        }

        $handle = (string)$order->pay->handle;

        try {
            $payConfig = PayProfile::config($order->pay);
        } catch (JSONException $e) {
            self::callbackFail($handle, "config", self::CALLBACK_REJECT, $tradeNo, $map, "支付配置不存在，无法验签：" . $e->getMessage());
            return self::CALLBACK_REJECT;
        }

        $callback = $this->callbackInitialize($order->pay, $map, $payConfig);

        $verifiedTradeNo = (string)($callback['trade_no'] ?? '');
        if ($verifiedTradeNo === '' || !hash_equals((string)$order->trade_no, $verifiedTradeNo)) {
            self::callbackFail($handle, "mismatch", self::CALLBACK_REJECT, (string)$order->trade_no, $map, "报文中取不到订单号、或与回调地址的订单号不一致，无法确认这笔回调属于本单，已拒绝");
        }

        $tradeNo = (string)$order->trade_no;
        DB::connection()->getPdo()->exec("set session transaction isolation level serializable");
        DB::transaction(function () use ($handle, $map, $callback, $tradeNo) {
            $order = \App\Model\Order::query()->where("trade_no", $tradeNo)->first();
            if (!$order) {
                self::callbackFail($handle, "not_found", self::CALLBACK_REJECT, $tradeNo, $map, "订单不存在");
            }
            if ((int)$order->status !== 0) {
                self::callbackFail($handle, "duplicate", self::CALLBACK_REJECT, $tradeNo, $map, "重复通知，当前订单已支付");
            }

            $paidAmount = $callback['amount'] ?? null;
            if (!is_scalar($paidAmount) || !is_numeric((string)$paidAmount)) {
                self::callbackFail($handle, "amount", self::CALLBACK_REJECT, $tradeNo, $map, "回调金额不是合法数字");
            }

            $expectSource = $order->gateway_amount !== null ? (string)$order->gateway_amount : (string)$order->amount;
            $expectAmount = (new Decimal($expectSource, 2))->getAmount();
            $actualAmount = (new Decimal((string)$paidAmount, 2))->getAmount();
            if (!hash_equals($expectAmount, $actualAmount)) {
                self::callbackFail($handle, "amount", self::CALLBACK_REJECT, $tradeNo, $map, "订单金额不匹配");
            }

            if ($order->owner != 0 && $owner = User::query()->find($order->owner)) {
                $owner->recharge = $owner->recharge + $order->amount;
                $owner->save();
            }
            $this->orderSuccess($order);
        });
        return $callback['success'];
    }

    public function getTradeAmount(
        ?User              $user,
        ?UserGroup         $userGroup,
        int                $cardId,
        int                $num,
        string             $coupon,
        int|Commodity|null $commodityId,
        ?string            $race = null,
        ?array             $sku = [],
        bool               $disableShared = false
    ): array
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
        $config = Ini::toArray((string)$commodity->config);

        if (!empty($config['category']) && is_array($config['category']) && !array_key_exists((string)$race, $config['category'])) {
            throw new JSONException("宝贝分类选择错误");
        }

        if (!empty($config['sku']) && is_array($config['sku'])) {
            if (empty($sku) || !is_array($sku)) {
                throw new JSONException("请选择SKU");
            }

            foreach ($config['sku'] as $sk => $ks) {
                if (!array_key_exists($sk, $sku)) {
                    throw new JSONException("请选择{$sk}");
                }

                if (!is_array($ks) || !array_key_exists($sku[$sk], $ks)) {
                    throw new JSONException("{$sk}中不存在{$sku[$sk]}，请选择正确的SKU");
                }
            }
        }

        $shopService = Di::inst()->make(\App\Service\Shop::class);

        $data['card_count'] = $shopService->getItemStock($commodityId, $race, $sku);

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
        $amount = $this->calcAmount($ow, $num, $commodity, $userGroup, $race, sku: $sku);
        if ($cardId != 0 && $commodity->draft_status == 1) {
            $amount = $amount + $commodity->draft_premium;
        }

        $couponMoney = 0;

        $price = $amount / $num;

        if ($coupon != "") {
            $voucher = Coupon::query()->where("code", $coupon)->first();

            if (!$voucher) {
                throw new JSONException("该优惠券不存在");
            }

            if ($voucher->owner != $commodity->owner) {
                throw new JSONException("该优惠券不存在");
            }

            if ($voucher->commodity_id != 0 && $voucher->commodity_id != $commodity->id) {
                throw new JSONException("该优惠券不属于该商品");
            }

            if ($voucher->race && $voucher->commodity_id != 0) {
                if ($race != $voucher->race) {
                    throw new JSONException("该优惠券不能抵扣当前商品");
                }
            }

            if ($voucher->sku && is_array($voucher->sku) && $voucher->commodity_id != 0) {
                if (!is_array($sku)) {
                    throw new JSONException("此优惠券不适用当前商品");
                }

                foreach ($voucher->sku as $key => $sk) {
                    if (!isset($sku[$key])) {
                        throw new JSONException("此优惠券不适用此SKU");
                    }

                    if ($sk != $sku[$key]) {
                        throw new JSONException("此优惠券不适用此SKU{$sku[$key]}");
                    }
                }
            }

            if ($voucher->commodity_id == 0 && $voucher->category_id != 0 && $voucher->category_id != $commodity->category_id) {
                throw new JSONException("该优惠券不能抵扣当前商品");
            }

            if ($voucher->status != 0) {
                throw new JSONException("该优惠券已失效");
            }

            if ($voucher->expire_time != null && strtotime($voucher->expire_time) < time()) {
                throw new JSONException("该优惠券已过期");
            }

            if ($voucher->mode == 0 && $voucher->money >= $amount) {
                throw new JSONException("该优惠券面额大于订单金额");
            }

            $deduction = $voucher->mode == 0 ? $voucher->money : (new Decimal($price, 2))->mul($voucher->money)->getAmount();

            $amount = (new Decimal($amount))->sub($deduction)->getAmount();
            $couponMoney = $deduction;
        }

        $data ['amount'] = $amount;
        $data ['price'] = (new Decimal($price))->getAmount();
        $data ['couponMoney'] = (new Decimal($couponMoney))->getAmount();

        return $data;
    }

    public function giftOrder(Commodity $commodity, string $race = "", int $num = 1, string $contact = "", string $password = "", ?int $cardId = null, int $userId = 0, string $widget = "[]"): array
    {
        return DB::transaction(function () use ($race, $widget, $contact, $password, $num, $cardId, $commodity, $userId) {
            $lockedCommodity = $this->lockCommodityForOrder($commodity);
            $this->lockLocalDraftCardForOrder($lockedCommodity, (int)$cardId);

            $date = Date::current();
            $order = new  \App\Model\Order();
            $order->owner = $userId;
            $order->trade_no = Str::generateTradeNo();
            $order->amount = 0;
            $order->commodity_id = $lockedCommodity->id;
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

            $order->leave_message = $lockedCommodity->leave_message;
            $order->rent = 0;
            $order->race = $race;
            $order->user_id = $lockedCommodity->owner;
            $order->setRelation('commodity', $lockedCommodity);
            $order->save();
            $secret = $this->orderSuccess($order);
            return [
                "secret" => $secret,
                "tradeNo" => $order->trade_no,
                "leave_message" => \App\Model\Order::resolveLeaveMessage($order->leave_message, null)
            ];
        });
    }

    private function echoSafe(mixed $value, int $limit = 32): string
    {
        $text = is_scalar($value) ? (string)$value : gettype($value);
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
    }
}
