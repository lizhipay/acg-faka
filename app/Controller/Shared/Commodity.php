<?php
declare(strict_types=1);

namespace App\Controller\Shared;


use App\Controller\Base\API\Shared;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\SharedValidation;
use App\Interceptor\Waf;
use App\Model\Card;
use App\Model\Category;
use App\Service\Order;
use App\Service\Query;
use App\Util\Ini;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\Post;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, SharedValidation::class], Interceptor::TYPE_API)]
class Commodity extends Shared
{
    #[Inject]
    private Order $order;

    #[Inject]
    private Query $query;

    #[Inject]
    private \App\Service\Shared $shared;

    /**
     * @return array
     * @throws JSONException
     */
    public function items(): array
    {
        $items = Category::query()->with(['children' => function (Relation $relation) {
            $relation->where("api_status", 1)->where("status", 1);
        }])->where("status", 1)->get();

        $list = $items->toArray(); //

        $userGroup = $this->getUserGroup();
        $userId = $this->getUser()->id;

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
                unset($list[$key]['children'][$index]['leave_message'], $list[$key]['children'][$index]['delivery_message']);
                //去掉原来的成本，准备计算拿货价
                $configs = Ini::toArray((string)$child['config']);
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
                    //将config array转换为配置文件
                    $list[$key]['children'][$index]['config'] = Ini::toConfig($configs);
                    $list[$key]['children'][$index]['factory_price'] = 0;
                } else {
                    //没有设置种类，计算会员价
                    $list[$key]['children'][$index]['factory_price'] = $this->order->calcAmount(owner: $userId, num: 1, disableSubstation: true, group: $userGroup, commodity: $commodity);
                }

            }
            //重组
            $list[$key]['children'] = array_values($list[$key]['children']);
        }

        $list = array_values($list);

        return $this->json(200, 'success', $list);
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
            if (!$this->shared->inventoryState($shared, $commodity->shared_code, $cardId, $num, $race)) {
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
            $inventory = $this->shared->inventory($shared, $commodity->shared_code, $race);
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
     * @return array
     * @throws JSONException
     */
    public function trade(): array
    {
        $_POST['pay_id'] = 1; //强制走余额支付

        $commodity = \App\Model\Commodity::query()->where("code", (string)$_POST['shared_code'])->first();

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }
        $_POST['commodity_id'] = $commodity->id;
        return $this->json(200, 'success', $this->order->trade($this->getUser(), $this->getUserGroup(), $_POST));
    }

    /**
     * @param string $sharedCode
     * @param int $page
     * @param int $limit
     * @param string $race
     * @return array
     * @throws JSONException
     */
    public function draftCard(#[Post] string $sharedCode, #[Post] int $page, #[Post] int $limit, #[Post] string $race): array
    {
        $commodity = \App\Model\Commodity::query()->where("code", $sharedCode)->first();

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        if ($commodity->status != 1) {
            throw new JSONException("该商品暂未上架");
        }

        if ($commodity->draft_status != 1) {
            throw new JSONException("该商品不支持预选");
        }

        $shared = $commodity->shared;

        //如果是套娃，直接拉远程服务器数据
        if ($shared) {
            $draftCard = $this->shared->draftCard($shared, $commodity->shared_code, $limit, $page, $race);
            return $this->json(200, "success", $draftCard);
        }

        //解析配置文件
        $parseConfig = Ini::toArray((string)$commodity->config);
        if (key_exists("category", $parseConfig)) {
            $commodity->race = $parseConfig['category'];
            if (!key_exists($race, $commodity->race)) {
                throw new JSONException("请选择商品种类");
            }
        }

        $map = ["equal-commodity_id" => (string)$commodity->id, "equal-status" => 0];

        if ($race) {
            $map['equal-race'] = $race;
        }

        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(Card::class);
        $queryTemplateEntity->setLimit($limit);
        $queryTemplateEntity->setPage($page);
        $queryTemplateEntity->setWhere($map);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setField(['id', 'draft']);
        $data = $this->query->findTemplateAll($queryTemplateEntity)->toArray();
        return $this->json(200, null, $data);
    }

}