<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Consts\Hook;
use App\Controller\Base\API\User;
use App\Entity\Query\Get;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Model\Card;
use App\Model\Commodity;
use App\Model\Config;
use App\Model\Order;
use App\Model\Pay;
use App\Model\UserCommodity;
use App\Service\Query;
use App\Service\Shared;
use App\Service\Shop;
use App\Util\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Util\Decimal;

#[Interceptor([Waf::class, UserVisitor::class])]
class Index extends User
{
    #[Inject]
    private Shared $shared;

    #[Inject]
    private Query $query;

    #[Inject]
    private \App\Service\Order $order;

    #[Inject]
    private Shop $shop;


    /**
     * @return array
     */
    public function data(): array
    {
        $category = $this->shop->getCategory($this->getUserGroup());
        hook(Hook::USER_API_INDEX_CATEGORY_LIST, $category);
        return $this->json(200, "success", $category);
    }

    /**
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     */
    public function commodity(): array
    {
        $keywords = (string)$_GET['keywords'];
        $limit = (int)$_GET['limit'];
        $page = (int)$_GET['page'];
        $categoryId = $_GET['categoryId'];

        $commodity = Commodity::query()
            ->with(['owner' => function (Relation $relation) {
                $relation->select(["id", "username", "avatar"]);
            }, 'category' => function (Relation $relation) {
                $relation->select(["id", "name", "icon"]);
            }]);

        if ($categoryId == 'recommend') {
            $commodity = $commodity->where("recommend", 1);
        } elseif ($categoryId != 0) {
            $commodity = $commodity->where("category_id", $categoryId);
        }

        if ($keywords != "") {
            $commodity = $commodity->where('name', 'like', '%' . $keywords . '%');
        }

        $bus = \App\Model\Business::get();
        $userCommodityMap = []; //自定义名称的MAP

        if ($bus) {
            //商家
            if ($bus->master_display == 0) {
                $commodity = $commodity->where("owner", $bus->user_id);
            } else {
                //查出所有自己定义的商品
                $userCommodity = UserCommodity::query()->where("user_id", $bus->user_id)->get();
                //隐藏的分类ID
                $hideCommodity = [];

                foreach ($userCommodity as $userComm) {
                    if ($userComm->status == 0) {
                        $hideCommodity[] = $userComm->commodity_id;
                    } else {
                        $userCommodityMap[$userComm->commodity_id] = $userComm;
                    }
                }

                $commodity = $commodity->whereNotIn("id", $hideCommodity)->whereRaw("(`owner`=0 or `owner`={$bus->user_id})");
            }
        } else {
            //主站
            if (Config::get("substation_display") == 1) {
                $let = "(`owner`=0 or ";
                //显示商家
                $list = (array)json_decode(Config::get("substation_display_list"), true);
                foreach ($list as $userId) {
                    $let .= "`owner`={$userId} or ";
                }
                $let = trim(trim($let), "or") . ")";
                $commodity = $commodity->whereRaw($let);
            } else {
                $commodity = $commodity->where("owner", 0);
            }
        }

        $commodity = $commodity
            ->where("status", 1)
            ->orderBy("sort", "asc")
            ->select([
                'id', 'name', 'cover',
                'status', 'delivery_way', 'price',
                'user_price',
                'level_disable', 'level_price', 'hide', 'owner', "recommend", 'category_id', 'stock', 'shared_id'
            ])
            ->withCount(['order as order_sold' => function (Builder $relation) {
                $relation->where("delivery_status", 1);
            }]);
        if ($limit == 0) {
            $commodity = $commodity
                ->get();
            $total = count($commodity);
            $data = $commodity->toArray();
        } else {
            $commodity = $commodity
                ->paginate($limit, ["*"], "", $page);
            $total = $commodity->total();
            $data = $commodity->toArray()['data'];
        }

        $user = $this->getUser();
        $userGroup = $this->getUserGroup();
        //取得分类
        $category = $this->shop->getCategory($userGroup);
        $cates = [];
        foreach ($category as $cate) {
            $cates[] = (string)$cate['id'];
        }

        //最终的商品数据遍历
        foreach ($data as $key => $val) {
            $parseGroupConfig = Commodity::parseGroupConfig($val['level_price'], $userGroup);
            if (!in_array((string)$val['category_id'], $cates) || $val['hide'] == 1 && (!$parseGroupConfig || !isset($parseGroupConfig['show']) || $parseGroupConfig['show'] != 1)) {
                //隐藏商品
                unset($data[$key]);
                continue;
            }

            if ($val['delivery_way'] == 0 && !$val['shared_id']) {
                $data[$key]['stock'] = Card::query()->where("status", 0)->where("commodity_id", $val['id'])->count();
            }

            //如果登录后，则自动计算登录后的价格
            if ($user) {
                $tradeAmount = $this->order->valuation(commodity: $commodity[$key], group: $userGroup);
                $data[$key]['price'] = $tradeAmount;
                $data[$key]['user_price'] = $tradeAmount;
            }

            unset(
                $data[$key]['level_price'],
                $data[$key]['level_disable']
            );

            if (!$val['cover']) {
                $data[$key]['cover'] = "/favicon.ico";
            }

            //分站自定义名称和价格
            if (isset($userCommodityMap[$val['id']])) {
                $var = $userCommodityMap[$val['id']];

                if ($var->premium > 0) {
                    $data[$key]['price'] = (new Decimal($data[$key]['price'], 2))->mul($var->premium / 100)->add($data[$key]['price'])->getAmount();
                    $data[$key]['user_price'] = (new Decimal($data[$key]['user_price'], 2))->mul($var->premium / 100)->add($data[$key]['user_price'])->getAmount();
                }
                if ($var->name) {
                    $data[$key]['name'] = $var->name;
                }
            }
        }

        $data = array_values($data);
        hook(Hook::USER_API_INDEX_COMMODITY_LIST, $data);
        $json = $this->json(200, "success", $data);
        $json['total'] = $total;
        return $json;
    }

    /**
     * @param int $commodityId
     * @return array
     */
    public function commodityDetail(int $commodityId): array
    {
        $array = $this->shop->getItem($commodityId, $this->getUser(), $this->getUserGroup());
        hook(Hook::USER_API_INDEX_COMMODITY_DETAIL_INFO, $array);
        return $this->json(200, 'success', $array);
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function card(): array
    {
        $map = $this->request->post();
        /**
         * @var Commodity $commodity
         */
        $commodity = Commodity::with(['shared'])->find($map['item_id']);
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
            //加价算法
            foreach ($data['list'] as &$item) {
                if ($item['draft_premium'] > 0) {
                    $item['draft_premium'] = $this->shared->AdjustmentAmount($commodity->shared_premium_type, $commodity->shared_premium, $item['draft_premium']);
                }
            }
        } else {
            $get = new Get(Card::class);
            $get->setPaginate((int)$this->request->post("page"), (int)$limit);
            $get->setWhere($map);
            $get->setColumn('id', 'draft', 'draft_premium');

            $data = $this->query->get($get, function (Builder $builder) use ($map) {
                $builder = $builder->where("commodity_id", $map['item_id'])->where("status", 0);

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

        //分站处理
        if (\App\Model\Business::state()) {
            foreach ($data['list'] as &$item) {
                if ($item['draft_premium'] > 0) {
                    $item['draft_premium'] = $this->shop->getSubstationPrice($commodity, $item['draft_premium']);
                }
            }
        }

        if ($commodity->level_disable != 1) {
            //折扣处理
            foreach ($data['list'] as &$item) {
                if ($item['draft_premium'] > 0) {
                    $item['draft_premium'] = $this->order->getValuationPrice($commodity->id, $item['draft_premium'], $this->getUserGroup());
                }
            }
        }

        return $this->json(data: $data);
    }


    /**
     * @return array
     */
    public
    function valuation(): array
    {
        $price = $this->order->valuation(
            commodity: (int)$this->request->post("item_id"),
            num: (int)$this->request->post("num"),
            race: (string)$this->request->post("race"),
            sku: (array)$this->request->post("sku"),
            cardId: (int)$this->request->post("card_id"),
            coupon: (string)$this->request->post("coupon"),
            group: $this->getUserGroup()
        );
        $price = $this->shop->getSubstationPrice((int)$this->request->post("item_id"), $price);
        return $this->json(data: ["price" => $price]);
    }


    /**
     * @return array
     */
    public
    function stock(): array
    {
        $stock = $this->shop->getItemStock((int)$this->request->post("item_id"), (string)$this->request->post("race"), (array)$this->request->post("sku"));
        return $this->json(data: ["stock" => $stock]);
    }

    /**
     * @return array
     */
    public
    function pay(): array
    {

        $equipment = 2;

        if (Client::isMobile()) {
            $equipment = 1;
        }

        if (Client::isWeChat()) {
            $equipment = 3;
        }

        $let = "(`equipment`=0 or `equipment`={$equipment})";

        if (!$this->getUser()) {
            $let .= " and id!=1";
        }

        $pay = Pay::query()->orderBy("sort", "asc")->where("commodity", 1)->whereRaw($let)->get(['id', 'name', 'icon', 'handle'])->toArray();

        hook(Hook::USER_API_INDEX_PAY_LIST, $pay);
        return $this->json(200, 'success', $pay);
    }


    /**
     * @param string $keywords
     * @return array
     */
    public function query(string $keywords): array
    {
        $keywords = trim($keywords);

        $get = new Get(Order::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setColumn('id', 'trade_no', 'sku', 'secret', 'user_id', 'password', 'amount', 'pay_id', 'commodity_id', 'create_time', 'pay_time', 'delivery_status', 'status', 'card_num', 'contact', "race");

        $data = $this->query->get($get, function (Builder $builder) use ($keywords) {

            $builder = $builder->with(['pay' => function (Relation $relation) {
                $relation->select(['id', 'name', 'icon']);
            }, 'commodity' => function (Relation $relation) {
                $relation->select(['id', 'name', 'cover', 'password_status', 'leave_message']);
            }]);

            if (preg_match('/^\d{18}$/', $keywords)) {
                $builder = $builder->where("trade_no", $keywords);
            } else {
                $builder = $builder->where("contact", $keywords);
            }
            return $builder;
        });

        foreach ($data['list'] as &$item) {
            if ($item['status'] != 1) {
                unset($item['commodity']['leave_message']);
                unset($item['secret']);
            }

            if (!empty($item['password'])) {
                $item['password'] = true;
                unset($item['secret']);
            }
        }

        hook(Hook::USER_API_INDEX_QUERY_LIST, $data);
        return $this->json(data: $data);
    }

    /**
     * @param string $tradeNo
     * @param string $password
     * @return array
     * @throws JSONException
     */
    public function secret(string $tradeNo, string $password): array
    {
        $order = Order::with(['commodity'])->where("trade_no", $tradeNo)->first();

        if (!$order) {
            throw new JSONException("未查询到相关信息");
        }

        if (!empty($order->password)) {
            if ($password != $order->password) {
                throw new JSONException("密码错误");
            }
        }

        if ($order->status != 1) {
            throw new JSONException("订单还未支付");
        }

        $widget = (array)json_decode((string)$order->widget, true);
        if (empty($widget)) {
            $widget = null;
        }

        hook(Hook::USER_API_INDEX_QUERY_SECRET, $order);
        return $this->json(data: [
            'secret' => $order->secret,
            'widget' => $widget,
            'leave_message' => $order?->commodity?->leave_message
        ]);
    }
}