<?php
declare(strict_types=1);

namespace App\Service\Bind;

use App\Consts\Hook;
use App\Model\Business;
use App\Model\Card;
use App\Model\Category;
use App\Model\Commodity;
use App\Model\Config;
use App\Model\User;
use App\Model\UserCategory;
use App\Model\UserCommodity;
use App\Model\UserGroup;
use App\Service\Shared;
use App\Util\Client;
use App\Util\Ini;
use App\Util\Tree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Plugin\Entity\Stock;
use Kernel\Util\Decimal;

class Shop implements \App\Service\Shop
{
    #[Inject]
    private Shared $shared;

    #[Inject]
    private \App\Service\Order $order;

    public function getCategory(?UserGroup $group): array
    {
        $category = Category::query()->withCount(['children as commodity_count' => function (Builder $builder) {
            $builder->where("status", 1);
        }])->where("status", 1)->orderBy("sort", "asc");

        $bus = Business::get();
        $userCategoryMap = [];
        $master = true;

        if ($bus) {
            $master = false;

            if ($bus->master_display == 0) {
                $category = $category->where("owner", $bus->user_id);
            } else {
                $userCategory = UserCategory::query()->where("user_id", $bus->user_id)->get();

                $hideCategory = [];

                foreach ($userCategory as $userCate) {
                    if ($userCate->status == 0) {
                        $hideCategory[] = $userCate->category_id;
                    } else {
                        $userCategoryMap[$userCate->category_id] = $userCate;
                    }
                }

                $category = $category->whereNotIn("id", $hideCategory)->whereRaw("(`owner`=0 or `owner`={$bus->user_id})");
            }
        } else {
            if (Config::get("substation_display") == 1) {
                $list = (array)json_decode(Config::get("substation_display_list"), true);
                $let = "(`owner`=0 or ";
                foreach ($list as $userId) {
                    $let .= "`owner`={$userId} or ";
                }
                $let = trim(trim($let), "or") . ")";
                $category = $category->whereRaw($let);
            } else {
                $category = $category->where("owner", 0);
            }
        }

        $category = $category->get();

        foreach ($category as $index => $item) {
            $levelConfig = $item->getLevelConfig($group);
            if ($item->hide == 1 && (!$levelConfig || !isset($levelConfig['show']) || (int)$levelConfig['show'] != 1)) {
                unset($category[$index]);
                continue;
            }

            if (isset($userCategoryMap[$item->id])) {
                $var = $userCategoryMap[$item->id];
                if ($var->name) {
                    $category[$index]['name'] = $var->name;
                }
            }

            if (!$item->icon) {
                $category[$index]['icon'] = '/favicon.ico';
            }
        }

        $array = $category->toArray();
        $array = array_values($array);

        $commodityRecommend = Config::get("commodity_recommend");
        if ($commodityRecommend == 1 && $master) {
            array_unshift($array, [
                "id" => 'recommend',

                "name" => lang((string)Config::get("commodity_name"), "dyn"),
                "sort" => 1,
                "create_time" => "-",
                "owner" => 0,
                "icon" => "/assets/static/images/recommend.png",
                "status" => 1,
                "hide" => 0,
                "user_level_config" => null,
                "commodity_count" => Commodity::query()->where("status", 1)->where("recommend", 1)->count(),
            ]);
        }

        return $array;
    }

    public function getItem(int|string $commodityId, ?User $user = null, ?UserGroup $group = null): array
    {
        \App\Util\Schema::ensureCommodityTags();

        $commodity = Commodity::query()->with(['owner' => function (Relation $relation) {
            $relation->select(["id", "username", "avatar"]);
        }])
            ->select(["id", "name", "description",
                "only_user", "purchase_count", "category_id", "cover", "price", "user_price",
                "status", "owner", "delivery_way", "contact_type", "password_status", "level_price",
                "level_disable", "coupon", "shared_id", "shared_code", "shared_premium", "shared_premium_type", "seckill_status",
                "seckill_start_time", "seckill_end_time", "draft_status", "draft_premium", "inventory_hidden",
                "widget", "minimum", "maximum", "shared_sync", "config", "stock", "code", "shared_amount_sync", "shared_config_sync",
                "tags"])
            ->withCount(['order as order_sold' => function (Builder $relation) {
                $relation->where("delivery_status", 1);
            }]);

        if (is_int($commodityId)) {
            $commodity = $commodity->find($commodityId);
        } else {
            $commodity = $commodity->where("code", $commodityId)->first();
        }

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        if ($commodity->status != 1) {
            throw new JSONException("该商品暂未上架");
        }

        $shared = \App\Model\Shared::query()->find($commodity->shared_id);

        if ($shared) {
            if ($commodity->shared_sync == 1) {
                $this->shared->syncRemoteItem($commodity->id);

                $fresh = Commodity::query()->find($commodity->id);
                if ($fresh) {
                    foreach ([
                        'price', 'user_price', 'config', 'level_price',
                        'draft_status', 'draft_premium', 'widget', 'stock',
                    ] as $field) {
                        $commodity->{$field} = $fresh->{$field};
                    }
                }
            }
        } else if ($commodity->delivery_way == 0) {
            $commodity->stock = Card::query()->where("commodity_id", $commodity->id)->where("status", 0)->count();

        }

        try {
            $this->order->parseConfig($commodity, $group);
        } catch (JSONException $e) {
            throw new JSONException("该商品配置异常，请商家检查商品[{$commodity->id}]的批发/规格/会员价配置：" . $e->getMessage());
        }

        $this->substationPriceIncrease($commodity);

        $commodity->service_url = Config::get("service_url");
        $commodity->service_qq = Config::get("service_qq");

        if ($commodity->draft_status == 1 && $commodity->draft_premium > 0 && $commodity->level_disable != 1) {
            $commodity->draft_premium = $this->order->getValuationPrice($commodity->id, $commodity->draft_premium, $group);
        }

        $array = $commodity->toArray();

        if (isset($array['config']) && is_array($array['config'])) {
            foreach ([
                'category_factory', 'wholesale_factory', 'category_wholesale_factory', 'sku_factory',
                'category_cost', 'sku_cost', 'shared_mapping',
            ] as $section) {
                unset($array['config'][$section]);
            }
        }

        if ($array["owner"]) {
            $business = Business::query()->where("user_id", $array["owner"]['id'])->first();
            if ($business) {
                $array['service_url'] = $business->service_url;
                $array['service_qq'] = $business->service_qq;
            }
        }

        if (!$array['cover']) {
            $array['cover'] = "/favicon.ico";
        }

        if (is_int($commodityId)) {
            $array['description'] = \App\Util\RichHtml::sanitize(
                (string)($array['description'] ?? ''),
                (int)$commodity->owner === 0
            );
        }

        $array['share_url'] = Client::getUrl() . "/item/{$array['id']}";
        $array['login'] = (bool)$user;
        if ($array['login']) {
            $array['share_url'] .= "?from={$user->id}";
        }

        $array['trade_captcha'] = (int)Config::get("trade_verification");

        if ($commodity->widget) {
            $array['widget'] = json_decode($commodity->widget, true);
        }

        $array['tags'] = Commodity::parseTags($array['tags'] ?? null);

        return $array;
    }

    public function getHideStock(int|string|null $stock): string
    {
        $stock = (int)$stock;

        return lang(match (true) {
            $stock <= 0 => "已售罄",
            $stock <= 5 => "即将售罄",
            $stock <= 20 => "一般",
            $stock <= 100 => "充足",
            default => "非常多"
        }, "tpl");
    }

    public function getStockState(int|string|null $stock): int
    {
        $stock = (int)$stock;
        return match (true) {
            $stock <= 0 => 0,
            $stock <= 5 => 1,
            $stock <= 20 => 2,
            $stock <= 100 => 3,
            default => 4
        };
    }

    public function getItemStock(int|Commodity|string $commodity, ?string $race = null, ?array $sku = []): string
    {
        if (is_int($commodity)) {
            $commodity = Commodity::with(['shared'])->find($commodity);
        } elseif (is_string($commodity)) {
            $commodity = Commodity::with(['shared'])->where("code", $commodity)->first();
        }

        if (!$commodity) throw new JSONException("商品不存在");

        if (($hook = \hook(Hook::SERVICE_SHOP_GET_ITEM_STOCK, $commodity, $race, $sku)) instanceof Stock) return $hook->getStock();

        if ($commodity->shared) {
            return $this->getSharedStock($commodity, $race, $sku);
        } else if ($commodity->delivery_way == 0) {
            $card = Card::query()->where("commodity_id", $commodity->id)->where("status", 0);
            if ($race) $card = $card->where("race", $race);
            if (!empty($sku)) {
                foreach ($sku as $k => $v) {
                    $card = $card->where("sku->{$k}", $v);
                }
            }
            return (string)$card->count();
        }
        return (string)$commodity->stock;
    }

    public function getSharedStockHash(int $id, ?string $race = null, ?array $sku = []): string
    {
        return md5($id . $race . json_encode($sku ?: []));
    }

    public function updateSharedStock(int|Commodity $commodity, ?string $race = null, ?array $sku = []): void
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }
        if (!$commodity) throw new JSONException("商品不存在");
        $hash = $this->getSharedStockHash($commodity->id, $race, $sku);
        $stock = is_array($commodity->shared_stock) ? $commodity->shared_stock : [];
        if (!array_key_exists($hash, $stock)) {
            return;
        }
        unset($stock[$hash]);
        Commodity::query()->where("id", $commodity->id)->update(["shared_stock" => $stock]);
        //缓存被判定失效 = 上游那边刚成交过，库存必然变了
        //hook() 的变参按引用接收，字面量传不进去，必须先落成变量
        $ebIds = [(int)$commodity->id];
        $ebAction = 'sync';
        $ebBefore = null;
        hook(Hook::COMMODITY_CHANGE_AFTER, $ebIds, $ebAction, $ebBefore);
    }

    public function getSharedStock(int|Commodity $commodity, ?string $race = null, ?array $sku = []): string|null
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }
        if (!$commodity) throw new JSONException("商品不存在");
        $hash = $this->getSharedStockHash($commodity->id, $race, $sku);

        if (!is_array($commodity->shared_stock) || !isset($commodity->shared_stock[$hash])) {
            $stock = $this->shared->getItemStock((clone $commodity), $commodity->shared, $commodity->shared_code, $race, $sku);
            $array = is_array($commodity->shared_stock) ? $commodity->shared_stock : [];
            $array[$hash] = $stock;
            Commodity::query()->where("id", $commodity->id)->update(["shared_stock" => $array]);
            //只有真正回源拿到新数据才广播；命中缓存的分支不走这里，天然自限流
            //hook() 的变参按引用接收，字面量传不进去，必须先落成变量
            $ebIds = [(int)$commodity->id];
            $ebAction = 'sync';
            $ebBefore = null;
            hook(Hook::COMMODITY_CHANGE_AFTER, $ebIds, $ebAction, $ebBefore);
            return $stock;
        }

        return $commodity->shared_stock[$hash];
    }

    public function getDraft(Commodity|int|string $commodity, int $cardId): array
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }
        if (!$commodity) throw new JSONException("商品不存在");

        $card = Card::query()->where("commodity_id", $commodity->id)->where("id", $cardId)->first();
        if (!$card) {
            throw new JSONException("预选的宝贝不存在");
        }

        if ($commodity->id != $card->commodity_id) {
            throw new JSONException("此预告信息不属于此商品");
        }

        if ($card->status != 0) {
            throw new JSONException("此宝贝已被他人抢走");
        }

        return ["draft_premium" => $card->draft_premium, "cost" => $card->cost];
    }

    public function substationPriceIncrease(Commodity &$commodity): void
    {
        $business = Business::get();

        if (!$business) {
            return;
        }

        $userCommodity = UserCommodity::query()->where("user_id", $business->user_id)->where("commodity_id", $commodity->id)->first();

        if (!$userCommodity) {
            return;
        }

        if ($userCommodity->name) {
            $commodity->name = $userCommodity->name;
        }

        if (trim((string)$userCommodity->description) !== '') {
            $commodity->description = $userCommodity->description;
        }

        $config = $commodity->config ?: [];

        if ($userCommodity->premium > 0) {
            $commodity->price = $userCommodity->applyRounding((new Decimal($commodity->price))->mul($userCommodity->premium / 100)->add($commodity->price)->getAmount());
            $commodity->user_price = $userCommodity->applyRounding((new Decimal($commodity->user_price))->mul($userCommodity->premium / 100)->add($commodity->user_price)->getAmount());

            if ($commodity->draft_premium > 0) {
                $commodity->draft_premium = $userCommodity->applyRounding((new Decimal($commodity->draft_premium))->mul($userCommodity->premium / 100)->add($commodity->draft_premium)->getAmount());
            }

            if (is_array($config['category'])) {
                foreach ($config['category'] as &$price) {
                    $price = $userCommodity->applyRounding((new Decimal($price))->mul($userCommodity->premium / 100)->add($price)->getAmount());
                }
            }

            if (is_array($config['wholesale'])) {
                foreach ($config['wholesale'] as &$price) {
                    $price = $userCommodity->applyRounding((new Decimal($price))->mul($userCommodity->premium / 100)->add($price)->getAmount());
                }
            }

            if (is_array($config['category_wholesale'])) {
                foreach ($config['category_wholesale'] as &$arr) {
                    foreach ($arr as &$price) {
                        $price = $userCommodity->applyRounding((new Decimal($price))->mul($userCommodity->premium / 100)->add($price)->getAmount());
                    }
                }
            }

            if (is_array($config['sku'])) {
                foreach ($config['sku'] as &$arr) {
                    foreach ($arr as &$price) {
                        $price = $userCommodity->applyRounding((new Decimal($price))->mul($userCommodity->premium / 100)->add($price)->getAmount());
                    }
                }
            }
        }

        $commodity->config = $config;
    }

    public function getSubstationPrice(Commodity|int $commodity, int|string|float $amount): string
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        $business = Business::get();

        if (!$business) {
            return (string)$amount;
        }

        $userCommodity = UserCommodity::query()->where("user_id", $business->user_id)->where("commodity_id", $commodity->id)->first();

        if (!$userCommodity) {
            return (string)$amount;
        }

        if ($userCommodity->premium > 0) {
            return $userCommodity->applyRounding((new Decimal($amount))->mul($userCommodity->premium / 100)->add($amount)->getAmount());
        }

        return (string)$amount;
    }
}