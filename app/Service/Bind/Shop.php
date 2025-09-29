<?php
declare(strict_types=1);

namespace App\Service\Bind;

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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Util\Decimal;

class Shop implements \App\Service\Shop
{
    #[Inject]
    private Shared $shared;

    #[Inject]
    private \App\Service\Order $order;

    /**
     * @param UserGroup|null $group
     * @return array
     * @throws RuntimeException
     */
    public function getCategory(?UserGroup $group): array
    {
        $category = Category::query()->withCount(['children as commodity_count' => function (Builder $builder) {
            $builder->where("status", 1);
        }])->where("status", 1)->orderBy("sort", "asc");

        $bus = Business::get();
        $userCategoryMap = []; //自定义名称的MAP
        $master = true;

        if ($bus) {
            $master = false;
            //商家
            if ($bus->master_display == 0) {
                $category = $category->where("owner", $bus->user_id);
            } else {
                //查询出所有不显示的ID
                $userCategory = UserCategory::query()->where("user_id", $bus->user_id)->get();
                //隐藏的分类ID
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
            //主站
            if (Config::get("substation_display") == 1) {
                //显示商家
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
        //拿到最终的分类数据
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
                "name" => Config::get("commodity_name"),
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

    /**
     * @param int|string $commodityId
     * @param User|null $user
     * @param UserGroup|null $group
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     */
    public function getItem(int|string $commodityId, ?User $user = null, ?UserGroup $group = null): array
    {

        $commodity = Commodity::query()->with(['owner' => function (Relation $relation) {
            $relation->select(["id", "username", "avatar"]);
        }])
            ->select(["id", "name", "description",
                "only_user", "purchase_count", "category_id", "cover", "price", "user_price",
                "status", "owner", "delivery_way", "contact_type", "password_status", "level_price",
                "level_disable", "coupon", "shared_id", "shared_code", "shared_premium", "shared_premium_type", "seckill_status",
                "seckill_start_time", "seckill_end_time", "draft_status", "draft_premium", "inventory_hidden",
                "widget", "minimum", "maximum", "shared_sync", "config", "stock"])
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

        /**
         * @var Shared $shared
         */
        $shared = \App\Model\Shared::query()->find($commodity->shared_id);

        if ($shared) {
            //远端同步
            if ($commodity->shared_sync == 1) {
                /**
                 * @var Commodity $new
                 */
                $new = Commodity::query()->find($commodity->id);

                $remoteItem = $this->shared->item($shared, $new->shared_code);

                $base = $this->shared->AdjustmentPrice(Ini::toConfig($remoteItem['config'] ?: []), (string)$remoteItem['price'], (string)$remoteItem['user_price'], $new->shared_premium_type, $new->shared_premium);

                $commodity->price = $new->price = $base['price'];
                $commodity->user_price = $new->user_price = $base['user_price'];
                $commodity->config = $new->config = Ini::toConfig($base['config']);
                $commodity->draft_status = $new->draft_status = $remoteItem['draft_status'];
                $commodity->draft_premium = $new->draft_premium = $remoteItem['draft_premium'] > 0 ? $this->shared->AdjustmentAmount($new->shared_premium_type, $new->shared_premium, $remoteItem['draft_premium']) : 0;
                $commodity->seckill_status = $new->seckill_status = $remoteItem['seckill_status'];
                $commodity->seckill_start_time = $new->seckill_start_time = $remoteItem['seckill_start_time'];
                $commodity->seckill_end_time = $new->seckill_end_time = $remoteItem['seckill_end_time'];
                $commodity->widget = $new->widget = json_encode($remoteItem['widget']);
                $commodity->minimum = $new->minimum = $remoteItem['minimum'];
                $commodity->maximum = $new->maximum = $remoteItem['maximum'];
                $commodity->stock = $new->stock = $remoteItem['stock'];
                $commodity->contact_type = $new->contact_type = $remoteItem['contact_type'];

                $new->save();
            }
        } else if ($commodity->delivery_way == 0) {
            $commodity->stock = Card::query()->where("commodity_id", $commodity->id)->where("status", 0)->count();

        }

        //解析商品配置
        $this->order->parseConfig($commodity, $group);


        //处理分站
        $this->substationPriceIncrease($commodity);

        $commodity->service_url = Config::get("service_url");
        $commodity->service_qq = Config::get("service_qq");

        if ($commodity->draft_status == 1 && $commodity->draft_premium > 0 && $commodity->level_disable != 1) {
            $commodity->draft_premium = $this->order->getValuationPrice($commodity->id, $commodity->draft_premium, $group);
        }

        $array = $commodity->toArray();

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

        $array['share_url'] = Client::getUrl() . "/item/{$array['id']}";
        $array['login'] = (bool)$user;
        if ($array['login']) {
            $array['share_url'] .= "?from={$user->id}";
        }

        //获取网站是否需要验证码
        $array['trade_captcha'] = (int)Config::get("trade_verification");

        if ($commodity->widget) {
            $array['widget'] = json_decode($commodity->widget, true);
        }

        return $array;
    }

    /**
     * @param int|string|null $stock
     * @return string
     */
    public function getHideStock(int|string|null $stock): string
    {
        $stock = (int)$stock;
        return match (true) {
            $stock <= 0 => "已售罄",
            $stock <= 5 => "即将售罄",
            $stock <= 20 => "一般",
            $stock <= 100 => "充足",
            default => "非常多"
        };
    }

    /**
     * @param int|string|null $stock
     * @return int
     */
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

    /**
     * @param int|Commodity|string $commodity
     * @param string|null $race
     * @param array|null $sku
     * @return string
     * @throws JSONException
     */
    public function getItemStock(int|Commodity|string $commodity, ?string $race = null, ?array $sku = []): string
    {
        if (is_int($commodity)) {
            $commodity = Commodity::with(['shared'])->find($commodity);
        } elseif (is_string($commodity)) {
            $commodity = Commodity::with(['shared'])->where("code", $commodity)->first();
        }

        if (!$commodity) throw new JSONException("商品不存在");
        //对接商品
        if ($commodity->shared) {
            return $this->getSharedStock($commodity, $race, $sku);
        } else if ($commodity->delivery_way == 0) {
            //库存
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


    /**
     * @param int $id
     * @param string|null $race
     * @param array|null $sku
     * @return string
     */
    public function getSharedStockHash(int $id, ?string $race = null, ?array $sku = []): string
    {
        return md5($id . $race . json_encode($sku ?: []));
    }


    /**
     * @param int|Commodity $commodity
     * @param string|null $race
     * @param array|null $sku
     * @return void
     * @throws JSONException
     */
    public function updateSharedStock(int|Commodity $commodity, ?string $race = null, ?array $sku = []): void
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }
        if (!$commodity) throw new JSONException("商品不存在");
        $hash = $this->getSharedStockHash($commodity->id, $race, $sku);
        $stock = is_array($commodity->shared_stock) ? $commodity->shared_stock : [];
        unset($stock[$hash]);
        Commodity::query()->where("id", $commodity->id)->update(["shared_stock" => $stock]);
    }

    /**
     * @param int|Commodity $commodity
     * @param string|null $race
     * @param array|null $sku
     * @return string|null
     * @throws JSONException
     */
    public function getSharedStock(int|Commodity $commodity, ?string $race = null, ?array $sku = []): string|null
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }
        if (!$commodity) throw new JSONException("商品不存在");
        $hash = $this->getSharedStockHash($commodity->id, $race, $sku);

        if (!is_array($commodity->shared_stock) || !isset($commodity->shared_stock[$hash])) {
            $stock = $this->shared->getItemStock($commodity->shared, $commodity->shared_code, $race, $sku);
            $array = is_array($commodity->shared_stock) ? $commodity->shared_stock : [];
            $array[$hash] = $stock;
            Commodity::query()->where("id", $commodity->id)->update(["shared_stock" => $array]);
            return $stock;
        }

        return $commodity->shared_stock[$hash];
    }


    /**
     * @param Commodity|int|string $commodity
     * @param int $cardId
     * @return array
     * @throws JSONException
     */
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

        return ["draft_premium" => $card->draft_premium];
    }


    /**
     * @param Commodity $commodity
     * @return void
     */
    public function substationPriceIncrease(Commodity &$commodity): void
    {
        $business = Business::get();

        if (!$business) {
            return;
        }

        /**
         * @var UserCommodity $userCommodity
         */
        $userCommodity = UserCommodity::query()->where("user_id", $business->user_id)->where("commodity_id", $commodity->id)->first();

        if (!$userCommodity) {
            return;
        }

        if ($userCommodity->name) {
            $commodity->name = $userCommodity->name;
        }

        $config = $commodity->config ?: [];

        if ($userCommodity->premium > 0) {

            $commodity->price = (new Decimal($commodity->price))->mul($userCommodity->premium / 100)->add($commodity->price)->getAmount();
            $commodity->user_price = (new Decimal($commodity->user_price))->mul($userCommodity->premium / 100)->add($commodity->user_price)->getAmount();

            if ($commodity->draft_premium > 0) {
                $commodity->draft_premium = (new Decimal($commodity->draft_premium))->mul($userCommodity->premium / 100)->add($commodity->draft_premium)->getAmount();
            }

            if (is_array($config['category'])) {
                foreach ($config['category'] as &$price) {
                    $price = (new Decimal($price))->mul($userCommodity->premium / 100)->add($price)->getAmount();
                }
            }


            if (is_array($config['wholesale'])) {
                foreach ($config['wholesale'] as &$price) {
                    $price = (new Decimal($price))->mul($userCommodity->premium / 100)->add($price)->getAmount();
                }
            }

            if (is_array($config['category_wholesale'])) {
                foreach ($config['category_wholesale'] as &$arr) {
                    foreach ($arr as &$price) {
                        $price = (new Decimal($price))->mul($userCommodity->premium / 100)->add($price)->getAmount();
                    }
                }
            }

            if (is_array($config['sku'])) {
                foreach ($config['sku'] as &$arr) {
                    foreach ($arr as &$price) {
                        $price = (new Decimal($price))->mul($userCommodity->premium / 100)->add($price)->getAmount();
                    }
                }
            }
        }

        $commodity->config = $config;
    }

    /**
     * @param Commodity|int $commodity
     * @param int|string|float $amount
     * @return string
     * @throws JSONException
     */
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

        /**
         * @var UserCommodity $userCommodity
         */
        $userCommodity = UserCommodity::query()->where("user_id", $business->user_id)->where("commodity_id", $commodity->id)->first();

        if (!$userCommodity) {
            return (string)$amount;
        }

        if ($userCommodity->premium > 0) {
            return (new Decimal($amount))->mul($userCommodity->premium / 100)->add($amount)->getAmount();
        }

        return (string)$amount;
    }

}