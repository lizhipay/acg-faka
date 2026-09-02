<?php
declare(strict_types=1);

namespace App\Controller\Shared;


use App\Controller\Base\API\Shared;
use App\Entity\Query\Get;
use App\Interceptor\SharedValidation;
use App\Interceptor\Waf;
use App\Model\Card;
use App\Model\Category;
use App\Service\Order;
use App\Service\Query;
use App\Service\Shop;
use App\Util\Ini;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor([Waf::class, SharedValidation::class], Interceptor::TYPE_API)]
class Commodity extends Shared
{
    #[Inject]
    private Order $order;

    #[Inject]
    private Query $query;

    #[Inject]
    private \App\Service\Shared $shared;

    #[Inject]
    private Shop $shop;

    /**
     * @return array
     * @throws JSONException
     */
    private function getItems(): array
    {
        $items = Category::query()->with(['children' => function (Relation $relation) {
            $relation->where("api_status", 1)->where("status", 1);
        }])->where("status", 1)->get();


        $list = $items->toArray();
        $userGroup = $this->getUserGroup();

        foreach ($list as $key => $item) {
            if (count($item['children']) == 0) {
                unset($list[$key]);
                continue;
            }
            foreach ($item['children'] as $index => $child) {
                $commodity = $items[$key]['children'][$index]; //直接拿到商品对象
                if (!$commodity || $commodity->id != $child['id']) {
                    unset($list[$key]['children'][$index]);
                    continue;
                }

                $parseGroupConfig = \App\Model\Commodity::parseGroupConfig($child['level_price'], $userGroup);
                if ($child['hide'] == 1 && (!$parseGroupConfig || !isset($parseGroupConfig['show']) || $parseGroupConfig['show'] != 1)) {
                    unset($list[$key]['children'][$index]);
                    continue;
                }

                if ($child['delivery_way'] == 0) { //stock
                    //套娃商品（本站自己也是从别处对接来的）卡池恒为空，按卡数算会把整批
                    //有货商品报成 0 库存，下游只能全部判缺货下架。真实读数在 shared_stock
                    //缓存里（getItemStock 每次现拉后回写）。这里只读缓存不现拉：items()
                    //是整站商品的批量出口，逐个现拉会把一次请求放大成几百次上游 HTTP。
                    $list[$key]['children'][$index]['stock'] = $commodity->shared_id
                        ? $this->sharedStockSnapshot($commodity)
                        : Card::query()->where("status", 0)->where("commodity_id", $child['id'])->count();
                }

                unset($list[$key]['children'][$index]['leave_message'], $list[$key]['children'][$index]['delivery_message']);
            }
            //重组
            $list[$key]['children'] = array_values($list[$key]['children']);
        }

        return array_values($list);
    }

    /**
     * 对接商品的库存快照——批量列表的尽力而为读数，两个来源取**较大**的一个：
     *   - `shared_stock` 缓存：按 md5(id+种类+sku) 分档存，多档取最大（求和会把
     *     「全规格」和「单规格」两种读数叠成假数）。只在成交后失效、没有 TTL，
     *     所以可能陈旧；
     *   - `stock` 列：syncRemoteItem（详情页每次访问）和自动货源接入（每轮同步）
     *     都会把上游读数写在这里，多数时候反而更新。
     * 两边都可能是过期的 0，谁也不能单独当准；取大是因为**少报的代价远大于多报**——
     * 少报会让下游整批判缺货自动下架，多报则在真正下单时被 inventoryState 逐级
     * 打到最终上游拦下。要精确读数的场合下游该走 stock / inventory 接口，那两个是现拉。
     */
    private function sharedStockSnapshot(\App\Model\Commodity $commodity): int
    {
        $max = (int)$commodity->stock;
        foreach ((array)$commodity->shared_stock as $value) {
            if (is_numeric($value) && (int)$value > $max) {
                $max = (int)$value;
            }
        }
        return $max;
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function items(): array
    {
        return $this->json(data: $this->getItems());
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function item(): array
    {
        $code = $_POST['code'] ?? null;
        if (!$code) {
            throw new JSONException("对接CODE不能为空");
        }
        $item = $this->shop->getItem($code);

        //#842 getItem() 的列白名单刻意不含 factory_price（那是本站自己的成本列，
        //不能进前台详情），但对接语义里下游要的 factory_price 是"它在本站的拿货价"——
        //即 inventory() 里按请求方身份现算的那个值。这里补同一份计算，否则下游
        //每轮 item 同步都会把进货价刷成 0。
        //注意：要在 getItem() 之后取模型，套娃商品的价格刚被里面的 syncRemoteItem 刷新过。
        $commodity = \App\Model\Commodity::query()->where("code", $code)->first();
        if ($commodity) {
            $userId = $this->getUser()->id;
            $userGroup = $this->getUserGroup();
            $factoryPrice = 0;
            $configs = Ini::toArray((string)$commodity->config);

            if (array_key_exists("category", $configs)) {
                //种类商品：单价合法为 0，逐种类算拿货价，与 inventory() 的 category_factory 同口径。
                //ini 数字种类名会被 PHP 数组转成 int 键，strict_types 下必须显式转回 string
                $factorys = [];
                foreach ($configs['category'] as $ck => $cv) {
                    try {
                        $factorys[$ck] = $this->order->calcAmount(owner: $userId, num: 1, disableSubstation: true, group: $userGroup, commodity: $commodity, race: (string)$ck);
                    } catch (\Error|\Exception $e) {
                        continue;
                    }
                }
                if (is_array($item['config'] ?? null)) {
                    //与 inventory() 同口径：陈旧的 category_factory 一律丢弃，只信本次现算
                    unset($item['config']['category_factory']);
                    if (count($factorys) != 0) {
                        $item['config']['category_factory'] = $factorys;
                    }
                }
            } else {
                $factoryPrice = $this->order->calcAmount(owner: $userId, num: 1, disableSubstation: true, group: $userGroup, commodity: $commodity);
            }

            $item['factory_price'] = $factoryPrice;
        }

        return $this->json(data: $item);
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function inventoryState(): array
    {
        $sharedCode = (string)$_POST['shared_code'];//商品CODE
        $cardId = (int)$_POST['card_id'];//预选的卡号ID
        $num = (int)$_POST['num']; //购买数量
        $race = (string)$_POST['race']; //类别

        if ($sharedCode == "") {
            throw new JSONException("商品代码不能为空");
        }
        $commodity = \App\Model\Commodity::query()->where("code", $sharedCode)->first();

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }
        if ($commodity->status != 1) {
            throw new JSONException("当前商品已停售");
        }

        $shared = $commodity->shared;
        //如果是套娃，直接拉远程服务器数据
        if ($shared) {
            if (!$this->shared->inventoryState($shared, $commodity, $cardId, $num, $race)) {
                throw new JSONException("库存不足");
            }
            return $this->json(200, "success");
        }

        //预选卡密
        if ($commodity->draft_status == 1 && $cardId != 0) {
            $card = Card::query()->find($cardId);
            if (!$card || $card->status != 0) {
                throw new JSONException("该卡已被他人抢走啦");
            }

            if ($card->commodity_id != $commodity->id) {
                throw new JSONException("该卡密不属于这个商品，无法预选");
            }
        } else {
            //自动发货，库存检测
            if ($commodity->delivery_way == 0) {
                $count = Card::query()->where("commodity_id", $commodity->id)->where("status", 0);

                if ($race) {
                    $count = $count->where("race", $race);
                }

                $count = $count->count();

                if ($count == 0 || $num > $count) {
                    throw new JSONException("库存不足");
                }
            }
        }
        return $this->json(200, "success");
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function inventory(): array
    {
        $sharedCode = (string)$_POST['sharedCode'];//商品CODE
        $race = (string)$_POST['race'];

        if ($sharedCode == "") {
            throw new JSONException("商品代码不能为空");
        }

        $commodity = \App\Model\Commodity::query()->where("code", $sharedCode)->first();

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        if ($commodity->status != 1) {
            throw new JSONException("当前商品已停售");
        }

        $count = 0;

        $shared = $commodity->shared;

        //如果是套娃，直接拉远程服务器数据
        if ($shared) {
            $inventory = $this->shared->inventory($shared, $commodity, $race);
            return $this->json(200, "success", $inventory);
        }

        if ($commodity->delivery_way == 0) {
            $count = Card::query()->where("commodity_id", $commodity->id)->where("status", 0);
            $parseConfig = Ini::toArray((string)$commodity->config);
            if (key_exists("category", $parseConfig)) {
                $commodity->race = $parseConfig['category'];
                if ($race) {
                    $count = $count->where("race", $race);
                } else {
                    foreach ($commodity->race as $key => $race) {
                        $count = $count->where("race", $key);
                        break;
                    }
                }
            }

            $count = $count->count();
        }

        //去掉原来的成本，准备计算拿货价
        $userId = $this->getUser()->id;
        $userGroup = $this->getUserGroup();

        $factoryPrice = 0;
        $isCategory = false;

        $configs = Ini::toArray((string)$commodity->config);
        if (array_key_exists("category_factory", $configs)) {
            unset($configs['category_factory']);
        }

        //检测是否设置了种类
        if (array_key_exists("category", $configs)) {
            //挨个计算成本
            $categorys = $configs['category'];
            $factorys = [];
            //这里ck = race种类名称，cv=单价
            foreach ($categorys as $ck => $cv) {
                $isCategory = true;
                //计算当前种类的成本
                try {
                    $factorys[$ck] = $this->order->calcAmount(owner: $userId, num: 1, disableSubstation: true, group: $userGroup, commodity: $commodity, race: $ck);
                } catch (\Error|\Exception $e) {
                    unset($configs['category'][$ck]);
                    continue;
                }
            }
            if (count($factorys) != 0) {
                //覆盖成本
                $configs['category_factory'] = $factorys;
            }
        } else {
            //没有设置种类，计算会员价
            $factoryPrice = $this->order->calcAmount(owner: $userId, num: 1, disableSubstation: true, group: $userGroup, commodity: $commodity);
        }

        //将config array转换为配置文件
        $cfg = Ini::toConfig($configs);


        return $this->json(200, "success", [
            'count' => $count,
            'delivery_way' => $commodity->delivery_way,
            "draft_status" => $commodity->draft_status,
            'price' => $commodity->price,
            'user_price' => $commodity->user_price,
            "config" => $cfg,
            "factory_price" => $factoryPrice,
            "is_category" => $isCategory
        ]);
    }

    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function trade(Request $request): array
    {
        $map = $request->post(flags: Filter::NORMAL);
        $map['pay_id'] = 1; //强制走余额支付

        $commodity = \App\Model\Commodity::query()->where("code", (string)$map['shared_code'])->first();

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        if ($commodity->status != 1) {
            throw new JSONException("当前商品已停售");
        }

        $config = Ini::toArray((string)$commodity->config);
        $race = (string)($map['race'] ?? '');

        if (!empty($config['category']) && is_array($config['category'])) {
            if (empty($race)) {
                throw new JSONException("该商品需要选择分类");
            }
            if (!array_key_exists($race, $config['category'])) {
                throw new JSONException("宝贝分类选择错误");
            }
        }

        $map['item_id'] = $commodity->id;
        return $this->json(200, 'success', $this->order->trade($this->getUser(), $this->getUserGroup(), $map));
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function draftCard(): array
    {
        $map = $this->request->post();
        /**
         * @var \App\Model\Commodity $commodity
         */
        $commodity = \App\Model\Commodity::query()->where("code", $map['code'])->first();
        $limit = $map['limit'] ?? 10;

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        if ($commodity->status != 1) {
            throw new JSONException("该商品暂未上架");
        }

        if ($commodity->draft_status != 1) {
            throw new JSONException("该商品不支持预选");
        }

        if ($commodity->shared) {
            $data = $this->shared->draftCard($commodity->shared, $commodity->shared_code, $map);
        } else {
            $get = new Get(Card::class);
            $get->setPaginate((int)$this->request->post("page"), (int)$limit);
            $get->setWhere($map);
            $get->setColumn('id', 'draft', 'draft_premium');

            $data = $this->query->get($get, function (Builder $builder) use ($map, $commodity) {
                $builder = $builder->where("commodity_id", $commodity->id)->where("status", 0);

                if (!empty($map['race'])) {
                    $builder = $builder->where("race", $map['race']);
                }

                if (!empty($map['sku']) && is_array($map['sku'])) {
                    foreach ($map['sku'] as $k => $v) {
                        $builder = $builder->where("sku->{$k}", $v);
                    }
                }

                return $builder;
            });
        }

        return $this->json(data: $data);
    }


    /**
     * @param string $tradeNo
     * @return array
     * @throws JSONException
     */
    public function query(string $tradeNo): array
    {
        /**
         * @var \App\Model\Order $order
         */
        $order = \App\Model\Order::query()->where("trade_no", $tradeNo)->where("owner", $this->getUser()->id)->first();

        if (!$order) {
            throw new JSONException("订单不存在");
        }

        $widget = (array)json_decode((string)$order->widget, true);
        if (empty($widget)) {
            $widget = null;
        }

        return $this->json(200, 'success', ['secret' => $order->secret, 'widget' => $widget, "status" => $order->status]);
    }


    /**
     * @return array
     */
    public function stock(): array
    {
        $map = $this->request->post(flags: Filter::NORMAL);
        $stock = $this->shop->getItemStock($map['code'], $map['race'] ?? null, $map['sku'] ?? null);
        return $this->json(data: ["stock" => $stock]);
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function valuation(): array
    {
        $commodity = \App\Model\Commodity::query()->where("code", $this->request->post("code"))->first();

        if (!$commodity) {
            throw new JSONException("商品不存在#0");
        }

        $price = $this->order->valuation(
            commodity: $commodity,
            num: (int)$this->request->post("num"),
            race: (string)$this->request->post("race"),
            sku: (array)$this->request->post("sku"),
            cardId: (int)$this->request->post("card_id"),
            group: $this->getUserGroup()
        );
        $price = $this->shop->getSubstationPrice($commodity, $price);
        //附带本站货币代码：对接双方币种不一致时，拉取侧能发现并告警（金额数字本身不换算）
        return $this->json(data: ["price" => $price, "currency_code" => \App\Util\Currency::code()]);
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function draft(): array
    {
        $map = $this->request->post(flags: Filter::NORMAL);
        $commodity = \App\Model\Commodity::query()->where("code", $map['code'])->first();

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        return $this->json(data: $this->shop->getDraft($commodity, (int)$map['card_id']));
    }
}