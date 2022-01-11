<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Model\Card;
use App\Model\Commodity;
use App\Model\Config;
use App\Model\Order;
use App\Model\Pay;
use App\Service\Query;
use App\Service\Shared;
use App\Util\Client;
use App\Util\Ini;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Get;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\Post;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, UserVisitor::class])]
class Index extends User
{
    #[Inject]
    private Shared $shared;

    #[Inject]
    private Query $query;

    #[Inject]
    private \App\Service\Order $order;


    /**
     * @return array
     * @throws JSONException
     */
    public function data(): array
    {
        $category = \App\Model\Category::query()->withCount(['children as commodity_count' => function (Builder $builder) {
            $builder->where("status", 1);
        }])->where("status", 1)->orderBy("sort", "asc");
        $bus = \App\Model\Business::get(Client::getDomain());
        if ($bus) {
            //商家
            if ($bus->master_display == 0) {
                $category = $category->where("owner", $bus->user_id);
            } else {
                $category = $category->whereRaw("(`owner`=0 or `owner`={$bus->user_id})");
            }
        } else {
            //主站
            if (Config::get("substation_display") == 1) {
                //显示商家
                $list = (array)json_decode(Config::get("substation_display_list"), true);
                $let = "(`owner`=0 or ";
                foreach ($list as $userId) {
                    // $category = $category->orWhere("owner", "=", $userId);
                    $let .= "`owner`={$userId} or ";
                }
                $let = trim(trim($let), "or") . ")";
                $category = $category->whereRaw($let);
            } else {
                $category = $category->where("owner", 0);
            }
        }
        $category = $category->get()->toArray();
        return $this->json(200, "success", $category);
    }

    /**
     * @param int $categoryId
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function commodity(#[Get] int $categoryId): array
    {
        $keywords = (string)$_GET['keywords'];

        $commodity = Commodity::query()->with(['shared' => function (Relation $relation) {
            $relation->select(['id']);
        }]);

        if ($categoryId != 0) {
            $commodity = $commodity->where("category_id", $categoryId);
        }

        if ($keywords != "") {
            $commodity = $commodity->where('name', 'like', '%' . $keywords . '%');
        }

        $bus = \App\Model\Business::get(Client::getDomain());
        if ($bus) {
            //商家
            if ($bus->master_display == 0) {
                $commodity = $commodity->where("owner", $bus->user_id);
            } else {
                $commodity = $commodity->whereRaw("(`owner`=0 or `owner`={$bus->user_id})");
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

        $commodity = $commodity->where("status", 1)->orderBy("sort", "asc")->get([
            'id', 'name', 'cover',
            'status', 'delivery_way', 'price',
            'user_price', 'inventory_hidden',
            'shared_id', 'shared_code',
            'level_disable', 'level_price'
        ]);

        $data = $commodity->toArray();
        $user = $this->getUser();
        $userGroup = $this->getUserGroup();
        foreach ($data as $key => $val) {
            $data[$key]['card_count'] = Card::query()->where("status", 0)->where("commodity_id", $val['id'])->count();
            //如果登录后，则自动计算登录后的价格
            if ($user) {
                $tradeAmount = $this->order->getTradeAmount($user, $userGroup, 0, 1, "", $commodity[$key]);
                $data[$key]['price'] = $tradeAmount['price'];
                $data[$key]['user_price'] = $tradeAmount['price'];
            }

            unset(
                $data[$key]['level_price'],
                $data[$key]['level_disable']
            );
        }
        return $this->json(200, "success", $data);
    }

    /**
     * @param int $commodityId
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function commodityDetail(#[Get] int $commodityId): array
    {
        $commodity = Commodity::query()->with(['owner' => function (Relation $relation) {
            $relation->select(["id", "username", "avatar"]);
        }])->find($commodityId, ["id", "name", "description", "only_user", "purchase_count", "category_id", "cover", "price", "user_price", "status", "owner", "delivery_way", "contact_type", "password_status", "level_price", "level_disable", "coupon", "shared_id", "shared_code", "seckill_status", "seckill_start_time", "seckill_end_time", "draft_status", "draft_premium", "inventory_hidden", "widget", "minimum", "shared_sync", "config"]);

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }
        if ($commodity->status != 1) {
            throw new JSONException("该商品暂未上架");
        }
        $shared = \App\Model\Shared::query()->find($commodity->shared_id);

        if ($shared) {
            $inventory = $this->shared->inventory($shared, $commodity->shared_code);
            $commodity->card = $inventory['count'];
            $commodity->delivery_way = $inventory['delivery_way'];
            $commodity->draft_status = $inventory['draft_status'];
            //同步远程平台价格
            if ($commodity->shared_sync == 1) {
                $new = Commodity::query()->find($commodity->id);
                if ((float)$commodity->price != (float)$inventory['price']) {
                    $new->price = (float)$inventory['price'];
                    $commodity->price = $new->price;
                }
                if ((float)$commodity->user_price != (float)$inventory['user_price']) {
                    $new->user_price = (float)$inventory['user_price'];
                    $commodity->user_price = $new->user_price;
                }
                $new->save();
            }
        }


        //检测是否登录
        $userGroup = $this->getUserGroup();
        //解析商品配置
        $this->order->parseConfig($commodity, $userGroup, $userGroup ? $this->getUser()->id : 0);

        if (!$shared && $commodity->delivery_way == 0) {
            $commodity->card = Card::query()->where("commodity_id", $commodity->id)->where("status", 0);
            if ($commodity->race) {
                foreach ($commodity->race as $key => $race) {
                    $commodity->card = $commodity->card->where("race", $key);
                    break;
                }
            }
            $commodity->card = $commodity->card->count();
        }

        if ($commodity->delivery_way == 0 && $commodity->card == 0 && !$commodity->race) {
            throw new JSONException("库存不足");
        }

        if ($userGroup) {
            $commodity->user_price = $this->order->calcAmount($this->getUser()->id, 1, $commodity, $userGroup);
        }

        $commodity->service_url = Config::get("service_url");
        $commodity->service_qq = Config::get("service_qq");

        $array = $commodity->toArray();

        if ($array["owner"]) {
            $business = \App\Model\Business::query()->where("user_id", $array["owner"]['id'])->first();
            if ($business) {
                $array['service_url'] = $business->service_url;
                $array['service_qq'] = $business->service_qq;
            }
        }

        $array['share_url'] = Client::getUrl() . "?code=" . urlencode(base64_encode(($this->getUser() ? "from=" . $this->getUser()->id . "&" : "") . "a={$array['category_id']}&b={$array['id']}"));
        $array['login'] = (bool)$this->getUser();

        //获取网站是否需要验证码
        $array['trade_captcha'] = (int)Config::get("trade_verification");


        return $this->json(200, 'success', $array);
    }

    /**
     * @param int $commodityId
     * @param int $page
     * @param string $race
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function card(#[Get] int $commodityId, #[Get] int $page, #[Get] string $race): array
    {
        $commodity = Commodity::query()->find($commodityId);
        $limit = $_GET['limit'] ?? 10;
        if (!$commodity) {
            throw new JSONException("商品不存在");
        }
        if ($commodity->status != 1) {
            throw new JSONException("该商品暂未上架");
        }
        if ($commodity->draft_status != 1) {
            throw new JSONException("该商品不支持预选");
        }

        //解析配置文件
        $parseConfig = Ini::toArray((string)$commodity->config);
        if (key_exists("category", $parseConfig)) {
            $commodity->race = $parseConfig['category'];
            if (!key_exists($race, $commodity->race)) {
                throw new JSONException("请选择商品种类");
            }
        }

        $shared = $commodity->shared;
        if ($shared) {
            $draftCard = $this->shared->draftCard($shared, $commodity->shared_code, (int)$limit, $page, $race);
            return $this->json(200, "success", $draftCard);
        } else {
            $map = ["equal-commodity_id" => (string)$commodityId, "equal-status" => 0];
            if ($race) {
                $map['equal-race'] = $race;
            }
            $queryTemplateEntity = new QueryTemplateEntity();
            $queryTemplateEntity->setModel(Card::class);
            $queryTemplateEntity->setLimit((int)$limit);
            $queryTemplateEntity->setPage($page);
            $queryTemplateEntity->setWhere($map);
            $queryTemplateEntity->setPaginate(true);
            $queryTemplateEntity->setField(['id', 'draft']);
            $data = $this->query->findTemplateAll($queryTemplateEntity)->toArray();
            return $this->json(200, "success", $data);
        }
    }


    /**
     * @param int $num
     * @param int $cardId
     * @param string $coupon
     * @param int $commodityId
     * @return array
     */
    public function tradeAmount(#[Post] int $num, #[Post] int $cardId, #[Post] string $coupon, #[Post] int $commodityId, #[Post] string $race): array
    {
        return $this->json(200, "success", $this->order->getTradeAmount($this->getUser(), $this->getUserGroup(), $cardId, $num, $coupon, $commodityId, $race));
    }


    /**
     * @return array
     */
    public function pay(): array
    {

        $equipment = 2;

        if (Client::isMobile()) {
            $equipment = 1;
        }

        $let = "(`equipment`=0 or `equipment`={$equipment})";

        $pay = Pay::query()->orderBy("sort", "asc")->where("commodity", 1)->whereRaw($let)->get(['id', 'name', 'icon', 'handle'])->toArray();
        return $this->json(200, 'success', $pay);
    }


    /**
     * @param string $keywords
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function query(#[Post] string $keywords): array
    {
        $keywords = trim($keywords);

        $filed = ['id', 'trade_no', 'user_id', 'amount', 'pay_id', 'commodity_id', 'create_time', 'pay_time', 'status', 'card_num', 'contact', "race"];


        $callback = function (Relation $relation) {
            $relation->select(['id', 'name', 'password_status', 'leave_message']);
        };

        $userCallback = function (Relation $relation) {
            $relation->with(['business' => function (Relation $relation) {
                $relation->select(['id', 'user_id', 'service_qq', 'service_url']);
            }])->select(['id', 'username', 'avatar']);
        };

        if ($keywords) {
            $order = Order::query()->where("trade_no", trim($keywords))->with(['pay', 'commodity' => $callback, 'user' => $userCallback])->get($filed);
            if (count($order) == 0) {
                $order = Order::query()->where("contact", trim($keywords))->with(['pay', 'commodity' => $callback, 'user' => $userCallback])->orderBy("id", "desc")->limit(10)->get($filed);
            }
        } else {
            $user = $this->getUser();
            if (!$user) {
                throw new JSONException("无数据");
            }
            $order = Order::query()->where("owner", $user->id)->orderBy("id", "desc")->limit(10)->with(['pay', 'commodity' => $callback, 'user' => $userCallback])->get($filed);
        }

        if (count($order) == 0) {
            throw new JSONException("无数据");
        }

        $serviceUrl = Config::get("service_url");
        $serviceQQ = Config::get("service_qq");
        foreach ($order as $key => $val) {
            if ($val->user instanceof \App\Model\User && $val->user->business instanceof \App\Model\Business) {
                $order[$key]->service_url = $val->user->business->service_url;
                $order[$key]->service_qq = $val->user->business->service_qq;
                $order[$key]->business_username = $val->user->username;
                $order[$key]->business_avatar = $val->user->avatar;
            } else {
                $order[$key]->service_url = $serviceUrl;
                $order[$key]->service_qq = $serviceQQ;
                $order[$key]->business_username = "官方自营";
                $order[$key]->business_avatar = "/favicon.ico";
            }


        }

        $orderArray = $order->toArray();

        foreach ($orderArray as $k => $v) {
            if ($v['commodity']) {
                if ($v['status'] == 0) {
                    $orderArray[$k]['leave_message'] = null;
                } else if ($v['status'] == 1) {
                    $orderArray[$k]['leave_message'] = $v['commodity']['leave_message'];
                }
                unset($orderArray[$k]['commodity']['leave_message']);
            }
        }

        //回显订单信息
        return $this->json(200, 'success', $orderArray);
    }

    /**
     * @param int $orderId
     * @param string $password
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function secret(#[Post] int $orderId, #[Post] string $password): array
    {
        $order = Order::query()->find($orderId);
        if (!$order) {
            throw new JSONException("未查询到相关信息");
        }
        $commodity = $order->commodity;
        if ($commodity->password_status == 1) {
            if ((string)$order->password != "" && $password != $order->password) {
                throw new JSONException("密码错误", 0);
            }
        }
        if ($order->status != 1) {
            throw new JSONException("该订单还未支付");
        }

        $widget = (array)json_decode((string)$order->widget, true);
        if (empty($widget)) {
            $widget = null;
        }

        return $this->json(200, 'success', ['secret' => $order->secret, 'widget' => $widget]);
    }
}