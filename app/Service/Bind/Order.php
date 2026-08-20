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
    /**
     * Fields which have already participated in price, input or delivery
     * decisions before trade() enters its database transaction. If one of
     * them changes while the request is being prepared, fail safely instead
     * of creating an order from a mixed old/new commodity snapshot.
     */
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

    /**
     * 回调失败一律回这一句，不区分原因。
     *
     * 订单号进了回调URL之后，"订单不存在"和"签名错误"两种文案的差异就是一个免鉴权的订单枚举口子：
     * 拿一个随便编的订单号打过来，看返回什么就知道这单存不存在。真实原因写进插件的 runtime.log
     * 和 SERVICE_PAY_CALLBACK_FAIL 钩子，站长排查照旧，网关只会拿到这一句。
     */
    public const CALLBACK_REJECT = "fail";

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
     * @param array|null $sku
     * @return float
     * @throws JSONException
     */
    public function calcAmount(int $owner, int $num, Commodity $commodity, ?UserGroup $group, ?string $race = null, bool $disableSubstation = false, ?array $sku = []): float
    {
        $premium = 0;

        //检测分站价格
        $bus = Business::get(Client::getDomain());
        if ($bus && !$disableSubstation) {
            if ($userCommodity = UserCommodity::getCustom($bus->user_id, $commodity->id)) {
                $premium = (float)$userCommodity->premium;
            }
        }

        //克隆后再解析：parseConfig会把config原地改成数组并清空level_price，
        //直接改传入的模型会污染调用方，循环调用时二次解析还会抛"配置解析异常"
        $commodity = clone $commodity;

        //会员等级自定义解析必须在parseConfig之前完成，parseConfig会清空level_price
        $userDefinedConfig = Commodity::parseGroupConfig((string)$commodity->level_price, $group);

        //解析配置文件
        $this->parseConfig($commodity, $group);
        $config = (array)$commodity->config;

        $price = $owner == 0 ? $commodity->price : $commodity->user_price;

        //种类商品：种类单价优先于商品基础单价
        if (!empty($race) && isset($config['category'][$race])) {
            $price = (float)$config['category'][$race];
        }

        //禁用任何折扣,直接计算
        if ($commodity->level_disable == 1) {
            return (int)(string)(($num * ($price + $premium)) * 100) / 100;
        }

        if ($userDefinedConfig && $userDefinedConfig['amount'] > 0) {
            if (empty($config['category'])) {
                //如果自定义价格成功，那么将覆盖其他价格
                $price = $userDefinedConfig['amount'];
            }
        } elseif ($group) {
            //如果没有对应的会员等级解析，那么就直接采用系统折扣
            $price = $price - ($price * $group->discount);
        }

        //判定是race还是普通订单
        if (!empty($config['category'])) {
            if (!empty($race) && isset($config['category_wholesale'][$race]) && is_array($config['category_wholesale'][$race])) {
                //判定当前race是否可以折扣
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
            //普通订单，直接走批发
            $list = (array)($config['wholesale'] ?? []);
            krsort($list);
            foreach ($list as $k => $v) {
                if ($num >= $k) {
                    $price = $v;
                    break;
                }
            }
        }

        //SKU加价，规则与valuation保持一致
        if (!empty($sku) && !empty($config['sku']) && is_array($config['sku'])) {
            foreach ($sku as $k => $v) {
                $skuPremium = $config['sku'][$k][$v] ?? 0;
                if (is_numeric($skuPremium) && $skuPremium > 0) {
                    $price += $skuPremium;
                }
            }
        }

        $price += $premium; //分站加价
        return (int)(string)(($num * $price) * 100) / 100;
    }


    /**
     * @param Commodity|int $commodity
     * @param int $num
     * @param string|null $race
     * @param array|null $sku
     * @param int|null $cardId
     * @param string|null $coupon
     * @param UserGroup|null $group
     * @return string
     * @throws JSONException
     * @throws \ReflectionException
     */
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

        //解析配置文件
        $this->parseConfig($commodity, $group);


        //算出race价格
        if (!empty($race) && !empty($commodity->config['category'])) {
            $_race = $commodity->config['category'];

            if (!isset($_race[$race])) {
                throw new JSONException("此商品类型不存在[{$race}]");
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

        //算出sku价格
        if (!empty($sku) && !empty($commodity->config['sku'])) {
            $_sku = $commodity->config['sku'];

            foreach ($sku as $k => $v) {
                if (!isset($_sku[$k])) {
                    throw new JSONException("此SKU不存在[{$k}]");
                }

                if (!isset($_sku[$k][$v])) {
                    throw new JSONException("此SKU不存在[{$v}]");
                }

                $_sku_price = $_sku[$k][$v] ?: 0;

                if (is_numeric($_sku_price) && $_sku_price > 0) {
                    $price = $price->add($_sku_price); //sku加价
                }
            }
        }


        //card自选加价
        if (!empty($cardId) && $commodity->draft_status == 1 && $num == 1) {

            /**
             * @var \App\Service\Shop $shop
             */
            $shop = Di::inst()->make(\App\Service\Shop::class);

            if ($commodity->shared) {
                $draft = $this->shared->getDraft($commodity->shared, $commodity->shared_code, $cardId);
                $draftPremium = $draft['draft_premium'] > 0 ? $this->shared->AdjustmentExtra($commodity, $draft['draft_premium']) : 0;
            } else {
                $draft = $shop->getDraft($commodity, $cardId);
                $draftPremium = $draft['draft_premium'];
            }

            if ($draftPremium > 0) {
                $price = $price->add($draftPremium); //卡密独立加价
            } else {
                $price = $price->add($commodity->draft_premium);
            }
        }


        //禁用任何折扣,直接计算
        if ($commodity->level_disable == 1) {
            return $price->mul($num)->getAmount();
        }


        //商品组优惠
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

        //优惠券折扣计算
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

            //race
            if ($voucher->race && $voucher->commodity_id != 0 && $race != $voucher->race) {
                throw new JSONException("该优惠券不能抵扣当前商品");
            }

            //sku
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

            //判断该优惠券是否有分类设定
            if ($voucher->commodity_id == 0 && $voucher->category_id != 0 && $voucher->category_id != $commodity->category_id) {
                throw new JSONException("该优惠券不能抵扣当前商品");
            }

            if ($voucher->status != 0) {
                throw new JSONException("该优惠券已失效");
            }

            //检测过期时间
            if ($voucher->expire_time != null && strtotime($voucher->expire_time) < time()) {
                throw new JSONException("该优惠券已过期");
            }

            //检测面额（仅金额券；百分比券 money 是 0~1 的比例，拿它和价格比会把低价商品误判成免单）
            if ($voucher->mode == 0 && $voucher->money >= $price->getAmount()) {
                return "0";
            }

            $deduction = $voucher->mode == 0 ? $voucher->money : $price->mul($voucher->money)->getAmount();
            $price = $price->sub($deduction);
        }

        //返回单价
        return $price->mul($num)->getAmount();
    }


    /**
     * @param Commodity|int $commodity
     * @param int $num
     * @param string|null $race
     * @param array|null $sku
     * @param int|null $cardId
     * @return string
     * @throws JSONException
     * @throws \ReflectionException
     */
    public function getCost(Commodity|int $commodity, int $num = 1, ?string $race = null, ?array $sku = [], ?int $cardId = null): string
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        $commodity = clone $commodity;

        //默认成本价
        $price = (new Decimal($commodity->factory_price, 2));

        //解析配置文件
        $config = Ini::toArray($commodity->config ?: "") ?: [];


        //算出race成本价格
        if (!empty($race) && !empty($config['category_cost'])) {
            $_race = $config['category_cost'];
            if (isset($_race[$race])) {
                $price = (new Decimal($_race[$race], 2));
            } else {
                $price = (new Decimal(0, 2));
            }
        }

        //算出sku成本价格
        if (!empty($sku) && !empty($config['sku_cost'])) {
            $_sku = $config['sku_cost'];
            foreach ($sku as $k => $v) {
                if (isset($_sku[$k][$v])) {
                    $_sku_price = $_sku[$k][$v] ?: 0;
                    if (is_numeric($_sku_price) && $_sku_price > 0) {
                        //成本add
                        $price = $price->add($_sku_price);
                    }
                }
            }
        }

        //card自选加价成本
        if (!empty($cardId) && $commodity->draft_status == 1 && $num == 1) {
            /**
             * @var \App\Service\Shop $shop
             */
            $shop = Di::inst()->make(\App\Service\Shop::class);

            if ($commodity->shared) {
                $draft = $this->shared->getDraft($commodity->shared, $commodity->shared_code, $cardId);
                $draftPremium = $draft['draft_premium']; //远程的本价，就是成本
            } else {
                $draft = $shop->getDraft($commodity, $cardId);
                $draftPremium = $draft['cost']; //本地的成本价
            }

            if ($draftPremium > 0) {
                $price = $price->add($draftPremium);
            }
        }

        //返回全部成本价
        return $price->mul($num)->getAmount();
    }

    /**
     * @param int $commodityId
     * @param string|float|int $price
     * @param UserGroup|null $group
     * @return string
     */
    public function getValuationPrice(int $commodityId, string|float|int $price, ?UserGroup $group = null): string
    {
        $price = new Decimal($price);

        //商品组优惠
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

    /**
     * 解析配置
     * @param Commodity $commodity
     * @param UserGroup|null $group
     * @return void
     * @throws JSONException
     */
    public function parseConfig(Commodity &$commodity, ?UserGroup $group): void
    {
        $parseConfig = Ini::toArray((string)$commodity->config);

        //用户组解析
        $userDefinedConfig = Commodity::parseGroupConfig($commodity->level_price, $group);

        if ($userDefinedConfig) {
            if (key_exists("category", $userDefinedConfig['config'])) {
                //$parseConfig['category'] = array_merge($parseConfig['category'] ?? [], $userDefinedConfig['config']['category']);
                $parseConfig['category'] = Arr::override($userDefinedConfig['config']['category'] ?? null, $parseConfig['category'] ?? null);
            }

            if (key_exists("wholesale", $userDefinedConfig['config'])) {
                //$parseConfig['wholesale'] = array_merge($parseConfig['wholesale'] ?? [], $userDefinedConfig['config']['wholesale']);
                $parseConfig['wholesale'] = Arr::override($userDefinedConfig['config']['wholesale'] ?? null, $parseConfig['wholesale'] ?? null);
            }

            if (key_exists("category_wholesale", $userDefinedConfig['config'])) {
                //$parseConfig['category_wholesale'] = array_merge($parseConfig['category_wholesale'] ?? [], $userDefinedConfig['config']['category_wholesale']);
                $parseConfig['category_wholesale'] = Arr::override($userDefinedConfig['config']['category_wholesale'] ?? null, $parseConfig['category_wholesale'] ?? null);
            }

            if (key_exists("sku", $userDefinedConfig['config'])) {
                //$parseConfig['sku'] = array_merge($parseConfig['sku'] ?? [], $userDefinedConfig['config']['sku']);
                $parseConfig['sku'] = Arr::override($userDefinedConfig['config']['sku'] ?? null, $parseConfig['sku'] ?? null);
            }
        }

        $commodity->config = $parseConfig;
        $commodity->level_price = null;
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
     * The commodity row must always be the first business row locked by an
     * order-creation transaction. Commodity deletion uses the same leading
     * lock, so an order can no longer be inserted after the deletion check.
     *
     * @throws JSONException
     */
    private function lockCommodityForOrder(Commodity $expected): Commodity
    {
        /** @var Commodity|null $locked */
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

        // Load the related platform only after the commodity row is locked.
        $locked->load('shared');
        return $locked;
    }

    /**
     * @throws JSONException
     */
    private function assertTradeCommoditySnapshot(Commodity $expected, Commodity $locked): void
    {
        foreach (self::ORDER_COMMODITY_SNAPSHOT_FIELDS as $field) {
            if ((string)$expected->getRawOriginal($field) !== (string)$locked->getRawOriginal($field)) {
                throw new JSONException('商品信息已经更新，请刷新后重新下单');
            }
        }
    }

    /**
     * Revalidate and lock a local preselected card inside the order transaction.
     * This prevents an administrator deletion from racing a new unpaid order
     * into retaining a card ID which no longer exists.
     *
     * @throws JSONException
     */
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

    /**
     * @param User|null $user
     * @param UserGroup|null $userGroup
     * @param array $map
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     * @throws \ReflectionException
     */
    public function trade(?User $user, ?UserGroup $userGroup, array $map): array
    {
        #CFG begin
        $commodityId = (int)$map['item_id'];//商品ID
        $contact = (string)$map['contact'];//联系方式
        $num = (int)$map['num']; //购买数量
        $cardId = (int)$map['card_id'];//预选的卡号ID
        $payId = (int)$map['pay_id'];//支付方式id
        $device = (int)$map['device'];//设备
        $password = (string)$map['password'];//查单密码
        $coupon = (string)$map['coupon'];//优惠券
        $from = $_COOKIE['promotion_from'] ?? 0;//推广人ID
        $owner = $user == null ? 0 : $user->id;
        $race = (string)$map['race']; //2022/01/09 新增，商品种类功能
        $requestNo = (string)$map['request_no'];
        $sku = $map['sku'] ?: null;
        #CFG end

        if ($user && $user->pid > 0) {
            $from = $user->pid;
        }

        if ($commodityId == 0) {
            throw new JSONException("请选择商品");
        }

        if ($num <= 0) {
            throw new JSONException("至少购买1个");
        }

        /**
         * @var Commodity $commodity
         */
        $commodity = Commodity::with(['shared'])->find($commodityId);


        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        if ($commodity->status != 1) {
            throw new JSONException("当前商品已停售");
        }

        //强制登录：全站开关（issue #791）或商品级"仅限会员购买"/限购
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

        //widget
        if ($commodity->widget) {
            $widgetList = (array)json_decode((string)$commodity->widget, true);
            foreach ($widgetList as $item) {
                //custom 类型是 JS 接管的展示容器（如人机验证），不是输入项：不校验、不入订单
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

        //预选卡密
        ($commodity->draft_status == 1 && $cardId != 0) && $num = 1;


        $regx = ['/^1[3456789]\d{9}$/', '/.*(.{2}@.*)$/i', '/[1-9]{1}[0-9]{4,11}/'];
        $msg = ['手机', '邮箱', 'QQ号'];
        //未登录才检测，登录后无需检测

        /**
         * @var \App\Service\Shop $shopService
         */
        $shopService = Di::inst()->make(\App\Service\Shop::class);

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

        //商品类型(race)与规格(sku)必选校验。
        //不校验会被这样绕过：商品配了分类却不提交 race —— valuation() 的分类定价分支
        //(!empty($race)) 直接跳过，按基础价计费；库存统计与发货取卡的 race 过滤同样是
        //条件式的，于是用最低价即可取走任意分类的卡密（含高价分类）。sku 同理，
        //少提交一个规格就少算一份加价。这里在计价与库存判断之前把参数钉死。
        $configCommodity = clone $commodity;
        $this->parseConfig($configCommodity, $userGroup);
        $commodityConfig = is_array($configCommodity->config) ? $configCommodity->config : [];

        if (!empty($commodityConfig['category']) && is_array($commodityConfig['category'])) {
            if ($race === '') {
                throw new JSONException("请选择商品类型");
            }
            if (!array_key_exists($race, $commodityConfig['category'])) {
                throw new JSONException("此商品类型不存在[{$race}]");
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
            //询价
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

        //计算订单价格
        $amount = $this->valuation($commodity, $num, $race, $sku, $cardId, $coupon, $userGroup);
        $rent == 0 && $rent = $this->getCost($commodity, $num, $race, $sku, $cardId);
        $rebate = 0;
        $divideAmount = 0;

        //分站相关
        $business = Business::get();
        if ($business) {
            $_user = User::query()->find($business->user_id);
            if ($commodity->owner === $business->user_id) {
                //自营商品
                $_level = BusinessLevel::query()->find($_user->business_level);
                $rebate = (new Decimal($amount))->sub((new Decimal($amount))->mul($_level->cost)->getAmount())->getAmount();
            } else {
                //分站提高价格
                $amount = $shopService->getSubstationPrice($commodity, $amount);
                $_userGroup = UserGroup::get($_user->recharge);
                //分站拿到的具体金额
                $rebate = (new Decimal($amount))->sub($this->valuation($commodity, $num, $race, $sku, $cardId, $coupon, $_userGroup))->getAmount();
            }
        } else {
            //主站卖分站的东西
            if ($commodity->owner > 0) {
                $_user = User::query()->find($commodity->owner);
                $_level = BusinessLevel::query()->find($_user->business_level);
                $rebate = (new Decimal($amount))->sub((new Decimal($amount))->mul($_level->cost)->getAmount())->getAmount();
            }
        }

        //推广者
        if ($from > 0 && $commodity->owner != $from && $owner != $from && (!$business || $business->user_id != $from)) {
            //佣金计算
            $x_user = User::query()->find($from);
            $x_userGroup = UserGroup::get($x_user->recharge);
            //推广者具体拿到的金额，计算方法：订单总金额 - 拿货价 = 具体金额
            $x_amount = $this->valuation($commodity, $num, $race, $sku, $cardId, $coupon, $x_userGroup);
            //先判定该订单是否分站或主站
            if ($rebate > 0) {
                $x_amount = $shopService->getSubstationPrice($commodity, $x_amount);
                //分站
                $x_divideAmount = (new Decimal($amount))->sub($x_amount)->getAmount();
                if ($rebate > $x_divideAmount) {
                    //当分站利益大过推广者的时候，才会给推广者进行分成
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

        //回调地址
        $callbackDomain = trim(Config::get("callback_domain"), "/");
        $clientDomain = Client::getUrl();

        if (!$callbackDomain) {
            $callbackDomain = $clientDomain;
        }

        DB::connection()->getPdo()->exec("set session transaction isolation level serializable");
        $result = Db::transaction(function () use ($commodity, $rent, $rebate, $divideAmount, $business, $sku, $requestNo, $user, $userGroup, $num, $contact, $device, $amount, $owner, $pay, $cardId, $password, $coupon, $from, $widget, $race, $callbackDomain, $clientDomain) {
            // Keep this as the first business-row lock in the transaction.
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

            //生成联系方式
            if ($user) {
                $contact = Str::generateRandStr(16);
            }

            if ($requestNo && \App\Model\Order::query()->where("request_no", $requestNo)->first()) {
                throw new JSONException("The request ID already exists");
            }


            $date = Date::current();
            $order = new  \App\Model\Order();
            $order->widget = $widget;
            //发货留言拍快照：站长后面换上游改了商品留言，老订单展示的仍是下单当时那份，
            //否则买家手里的卡密和说明会对不上（issue #813）
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


            //优惠券
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
                //免费赠送(0元订单不走任何支付，也不允许负数金额流入扣款逻辑)
                $order->amount = "0.00";
                $order->save();//先将订单保存下来
                $secret = $this->orderSuccess($order); //提交订单并且获取到卡密信息
                //0元单没有支付环节，url直接指向订单结果页，避免前端拿到null后相对跳转出 /item/null
                $url = $owner == 0
                    ? $clientDomain . '/user/index/query?tradeNo=' . $order->trade_no
                    : $clientDomain . '/user/personal/purchaseRecord?tradeNo=' . $order->trade_no;
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
                    //余额支付同样没有收银环节，补上结果页url，避免API调用方拿到null
                    $url = $clientDomain . '/user/personal/purchaseRecord?tradeNo=' . $order->trade_no;
                } else {
                    //开始进行远程下单
                    //增加接口手续费：0.9.6-beta
                    $order->pay_cost = $pay->cost_type == 0 ? $pay->cost : (new Decimal($order->amount, 2))->mul($pay->cost)->getAmount();
                    $order->amount = (new Decimal($order->amount, 2))->add($order->pay_cost)->getAmount();

                    //判断如果登录
                    if ($owner == 0) {
                        $returnUrl = $clientDomain . '/user/index/query?tradeNo=' . $order->trade_no;
                    } else {
                        $returnUrl = $clientDomain . '/user/personal/purchaseRecord?tradeNo=' . $order->trade_no;
                    }

                    $payObject = PayFactory::make(
                        $pay,
                        (string)$order->trade_no,
                        (float)$order->amount,
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
            //把发货留言一并带回：秒发商品下单后前端只弹卡密，买家看不到使用说明。
            //会员购买记录页和游客查询页早就在显示它了，唯独下单那一刻的弹窗没有。见 issue #816
            return ['url' => $url, 'amount' => $order->amount, 'tradeNo' => $order->trade_no, 'secret' => $secret, 'leave_message' => \App\Model\Order::resolveLeaveMessage($order->leave_message, null)];
        });
        $result["stock"] = $shopService->getItemStock($commodity, $race, $sku);
        return $result;
    }


    /**
     * 支付回调校验失败的统一出口：写支付插件日志 → 触发 SERVICE_PAY_CALLBACK_FAIL → 抛出给网关的错误。
     * 钩子里的异常不改变原有失败流程。
     * @param string $handle 支付插件
     * @param string $reason handle|not_found|credential|plugin|sign|status|duplicate|amount
     * @param string $error 返回给网关的错误文本
     * @param string|null $tradeNo
     * @param array $map 回调原始数据
     * @param string|null $logMessage 为空则不写支付插件日志
     * @param string $logType
     * @throws JSONException
     */
    public static function callbackFail(string $handle, string $reason, string $error, ?string $tradeNo, array $map, ?string $logMessage = null, string $logType = "CALLBACK"): void
    {
        //handle 现在可能是空的（新形态回调URL带的是订单号，插件名要查到订单才知道），
        //空的话别去拼 app/Pay//runtime.log 这种路径
        if ($logMessage !== null && $handle !== '' && Str::isValid($handle) && PayConfig::isValid($handle)) {
            PayConfig::log($handle, $logType, $logMessage);
        }
        try {
            hook(Hook::SERVICE_PAY_CALLBACK_FAIL, $handle, $reason, $tradeNo, $map);
        } catch (\Throwable $e) {
        }
        throw new JSONException($error);
    }

    /**
     * 初始化回调：加载插件、验签、验状态，返回报文里的订单号与金额。
     *
     * 首参从插件名改成了支付接口行——一个插件可以有多套配置(多商户号)，只有订单指向的那一行
     * 才知道该用哪套凭据验签。$payConfig 传入即用，不传则回落读插件目录里的旧配置文件。
     *
     * @param \App\Model\Pay $pay 订单所属的支付接口
     * @param array $map 回调原始数据
     * @param array|null $payConfig 该支付接口生效的配置
     * @return array
     * @throws JSONException
     */
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

        //检测签名验证是否开启
        if ($callback[\App\Consts\Pay::IS_SIGN]) {
            //核心兜底：验签已开启，但插件未配置任何凭据（密钥/密文/公钥）时直接拒绝，
            //防止空密钥导致 md5(data.'') 之类可被伪造的回调通过验签。
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
            //插件可能在验签过程中重写整个报文（微信重读php://input、蓝新解密TradeInfo），以重写后的为准
            $map = Context::get(\App\Consts\Pay::DAFA);
        }

        //验证状态
        if ($callback[\App\Consts\Pay::IS_STATUS]) {
            if ((string)($map[$callback[\App\Consts\Pay::FIELD_STATUS_KEY]] ?? '') !== (string)$callback[\App\Consts\Pay::FIELD_STATUS_VALUE]) {
                self::callbackFail($handle, "status", self::CALLBACK_REJECT, $tradeNo, $map, "状态验证失败");
            }
        }

        //拿到订单号和金额。订单号可能取不到（蓝新这类把订单号藏在密文里的插件），调用方据此决定是否比对
        return [
            "trade_no" => (string)($map[$callback[\App\Consts\Pay::FIELD_ORDER_KEY]] ?? ''),
            "amount" => $map[$callback[\App\Consts\Pay::FIELD_AMOUNT_KEY]] ?? null,
            "success" => $callback[\App\Consts\Pay::FIELD_RESPONSE]
        ];
    }


    /**
     * 判断支付插件是否配置了可用于验签的凭据（密钥/密文/公钥）。
     * 凭据字段名按各插件配置动态识别，不写死为 key。
     * 仅当"存在凭据字段但全部为空"时返回 false（拒绝回调）；若插件根本没有凭据字段，
     * 则不干预（返回 true，交由插件自身 verification 判定），避免误伤非常规插件。
     * @param array|null $config
     * @return bool
     */
    private static function payCredentialConfigured(?array $config): bool
    {
        //空配置必须拒绝，不能"交给插件自己判"——实测 24 个支付插件没有一个检查空密钥，
        //而 md5(报文 . '') 这种签名攻击者自己就能算出来，等于任何人都能把订单刷成已支付。
        //宁可回调失败让站长去填配置（日志里写得很清楚），也不能放行一笔没有凭据保护的回调。
        if (empty($config)) {
            return false;
        }
        $pattern = '/(secret|token|private_?key|public_?key|app_?secret|api_?key|mch_?key|md5_?key|(^|_)key$)/i';
        $found = false;
        foreach ($config as $k => $v) {
            if (is_string($k) && preg_match($pattern, $k)) {
                $found = true;
                if (is_string($v) && trim($v) !== '') {
                    return true; //至少有一个非空凭据
                }
            }
        }
        return !$found; //有凭据字段但全空→false(拒绝)；无凭据字段→true(不干预)
    }


    /**
     * 回调URL上带的那一段是不是合法订单号。
     *
     * 订单号是 Str::generateTradeNo() 生成的18位纯数字，所以只认纯数字。
     * 不用 is_file() 去探插件目录——把入口校验挂在文件系统上，
     * 等于让能往 app/Pay 落目录的人左右回调的受理逻辑。
     *
     * @param string $param
     * @return bool
     */
    public static function isCallbackTradeNo(string $param): bool
    {
        return $param !== '' && preg_match('/^\d+$/D', $param) === 1;
    }


    /**
     * @param \App\Model\Order $order
     * @return string
     * @throws JSONException
     */
    public function orderSuccess(\App\Model\Order $order): string
    {
        /**
         * @var Commodity $commodity
         */
        $commodity = $order->commodity;
        $order->pay_time = Date::current();
        $order->status = 1;
        $shared = $commodity->shared; //获取商品的共享平台

        if ($shared) {
            //拉取远程平台的卡密发货
            $order->secret = $this->shared->trade($shared, $commodity, $order->contact, $order->card_num, (int)$order->card_id, $order->create_device, (string)$order->password, (string)$order->race, $order->sku ?: [], $order->widget, $order->trade_no);
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
                //减少手动库存
                if ($commodity->stock >= $order->card_num) {
                    Commodity::query()->where("id", $commodity->id)->decrement('stock', $order->card_num);
                } else {
                    Commodity::query()->where("id", $commodity->id)->update(['stock' => 0]);
                }
            }
        }

        //推广者
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

    /**
     * 拉取本地卡密，需要事务环境执行
     * @param \App\Model\Order $order
     * @param Commodity $commodity
     * @return string
     */
    private function pullCardForLocal(\App\Model\Order $order, Commodity $commodity): string
    {
        $secret = "很抱歉，有人在你付款之前抢走了商品，请联系客服。";

        /**
         * @var Card $draft
         */
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
        } else {
            //订单没有类别时只能发无类别的卡密：早期数据或异常下单若漏掉 race，
            //这里不加限制就会从全部类别里随机发货，等于按基础价发出高价分类的卡。
            //取不到就保持未发货（与库存不足同一处理），不会误发。
            $cards = $cards->where(function ($query) {
                $query->whereNull("race")->orWhere("race", "");
            });
        }

        //判断sku存在
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
        $tradeNo = Firewall::inst()->xssKiller($tradeNo);

        //回调URL带的就是订单号，这是唯一形态。按插件名寻址（callback.Epay）已彻底移除，
        //拿插件名打进来的一律拒绝——支付方式和支付配置全部从订单反查，不接受外部声明。
        if (!self::isCallbackTradeNo($tradeNo)) {
            self::callbackFail('', "handle", self::CALLBACK_REJECT, null, $map);
        }

        $order = \App\Model\Order::with(['pay'])->where("trade_no", $tradeNo)->first();

        if (!$order || !$order->pay) {
            self::callbackFail('', "not_found", self::CALLBACK_REJECT, $tradeNo, $map);
        }

        $handle = (string)$order->pay->handle;
        //这单当初用的是哪套配置，就用哪套验签

        try {
            $payConfig = PayProfile::config($order->pay);
        } catch (JSONException $e) {
            self::callbackFail($handle, "config", self::CALLBACK_REJECT, $tradeNo, $map, "支付配置不存在，无法验签：" . $e->getMessage());
            return self::CALLBACK_REJECT; //callbackFail 必然抛出，这行只为静态分析
        }

        $callback = $this->callbackInitialize($order->pay, $map, $payConfig);

        //★ 验签之后，报文里的订单号必须就是URL指向的这一单，取不到也算失败。
        //少了这一步，一份合法签名的回调可以被重放到任意同金额的其他订单上把它刷成已支付；
        //充值场景金额还是用户自己填的，等于无限刷余额。
        //蓝新那种把订单号藏在AES密文里的插件也没问题——它在验签时会把解密后的报文写回
        //Context，这里读到的已经是解密后的订单号。
        $verifiedTradeNo = (string)($callback['trade_no'] ?? '');
        if ($verifiedTradeNo === '' || !hash_equals((string)$order->trade_no, $verifiedTradeNo)) {
            self::callbackFail($handle, "mismatch", self::CALLBACK_REJECT, (string)$order->trade_no, $map, "报文中取不到订单号、或与回调地址的订单号不一致，无法确认这笔回调属于本单，已拒绝");
        }

        $tradeNo = (string)$order->trade_no;
        DB::connection()->getPdo()->exec("set session transaction isolation level serializable");
        DB::transaction(function () use ($handle, $map, $callback, $tradeNo) {
            //获取订单
            $order = \App\Model\Order::query()->where("trade_no", $tradeNo)->first();
            if (!$order) {
                self::callbackFail($handle, "not_found", self::CALLBACK_REJECT, $tradeNo, $map, "订单不存在");
            }
            if ((int)$order->status !== 0) {
                self::callbackFail($handle, "duplicate", self::CALLBACK_REJECT, $tradeNo, $map, "重复通知，当前订单已支付");
            }
            //金额必须是标量数字：PHP 里 (float)任意非空数组 == 1.0，
            //直接强转会让 amount[]=x 这种传参在金额 1.00 的订单上蒙混过关。
            $paidAmount = $callback['amount'] ?? null;
            if (!is_scalar($paidAmount) || !is_numeric((string)$paidAmount)) {
                self::callbackFail($handle, "amount", self::CALLBACK_REJECT, $tradeNo, $map, "回调金额不是合法数字");
            }
            //用 bcmath 定标到两位小数做精确比较，避开浮点等值判断
            $expectAmount = (new Decimal((string)$order->amount, 2))->getAmount();
            $actualAmount = (new Decimal((string)$paidAmount, 2))->getAmount();
            if (!hash_equals($expectAmount, $actualAmount)) {
                self::callbackFail($handle, "amount", self::CALLBACK_REJECT, $tradeNo, $map, "订单金额不匹配");
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
     * @param array|null $sku
     * @param bool $disableShared
     * @return array
     * @throws JSONException
     * @throws \ReflectionException
     */
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

        //category和sku的配置都是 键=>价格 的映射，校验必须查键名而不是价格值
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

        /**
         * @var \App\Service\Shop $shopService
         */
        $shopService = Di::inst()->make(\App\Service\Shop::class);

        $data['card_count'] = $shopService->getItemStock($commodityId, $race, $sku);

//        if ($commodity->delivery_way == 0 && ($commodity->shared_id == null || $commodity->shared_id == 0)) {
//            if ($race) {
//                $data['card_count'] = Card::query()->where("commodity_id", $commodity->id)->where("status", 0)->where("race", $race)->count();
//            }
//        } elseif ($commodity->shared_id != 0) {
//            //查远程平台的库存
//            $shared = \App\Model\Shared::query()->find($commodity->shared_id);
//            if ($shared && !$disableShared) {
//                $inventory = $this->shared->inventory($shared, $commodity, (string)$race);
//                $data['card_count'] = $inventory['count'];
//            }
//        }

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
        $amount = $this->calcAmount($ow, $num, $commodity, $userGroup, $race, sku: $sku);
        if ($cardId != 0 && $commodity->draft_status == 1) {
            $amount = $amount + $commodity->draft_premium;
        }

        $couponMoney = 0;
        //优惠券
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

            //race
            if ($voucher->race && $voucher->commodity_id != 0) {
                if ($race != $voucher->race) {
                    throw new JSONException("该优惠券不能抵扣当前商品");
                }
            }

            //sku
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


            //判断该优惠券是否有分类设定
            if ($voucher->commodity_id == 0 && $voucher->category_id != 0 && $voucher->category_id != $commodity->category_id) {
                throw new JSONException("该优惠券不能抵扣当前商品");
            }

            if ($voucher->status != 0) {
                throw new JSONException("该优惠券已失效");
            }

            //检测过期时间
            if ($voucher->expire_time != null && strtotime($voucher->expire_time) < time()) {
                throw new JSONException("该优惠券已过期");
            }

            //检测面额（仅金额券；百分比券 money 是 0~1 的比例，低价订单会被误判"面额大于订单金额"而无法用券）
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
     * @throws RuntimeException
     * @throws \ReflectionException
     */
    public function giftOrder(Commodity $commodity, string $race = "", int $num = 1, string $contact = "", string $password = "", ?int $cardId = null, int $userId = 0, string $widget = "[]"): array
    {
        return DB::transaction(function () use ($race, $widget, $contact, $password, $num, $cardId, $commodity, $userId) {
            // Preserve gift-order semantics (including intentional gifts for a
            // stopped item), but serialize creation against physical deletion.
            $lockedCommodity = $this->lockCommodityForOrder($commodity);
            $this->lockLocalDraftCardForOrder($lockedCommodity, (int)$cardId);

            //创建订单
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
            //发货留言拍快照：站长后面换上游改了商品留言，老订单展示的仍是下单当时那份，
            //否则买家手里的卡密和说明会对不上（issue #813）
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
}
