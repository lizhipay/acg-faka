<?php
declare(strict_types=1);

namespace App\Controller\Shared;


use App\Controller\Base\API\Shared;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\SharedValidation;
use App\Model\Card;
use App\Model\Category;
use App\Service\Order;
use App\Service\Query;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Get;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\Post;
use Kernel\Exception\JSONException;

#[Interceptor(SharedValidation::class, Interceptor::TYPE_API)]
class Commodity extends Shared
{
    #[Inject]
    private Order $order;

    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function items(): array
    {
        $items = Category::query()->with(['children' => function (Relation $relation) {
            $relation->where("api_status", 1)->where("status", 1);
        }])->where("status", 1)->get();

        return $this->json(200, 'success', $items->toArray());
    }

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function inventoryState(): array
    {
        $sharedCode = (string)$_POST['shared_code'];//商品CODE
        $cardId = (int)$_POST['card_id'];//预选的卡号ID
        $num = (int)$_POST['num']; //购买数量
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
                $count = Card::query()->where("commodity_id", $commodity->id)->where("status", 0)->count();
                if ($count == 0 || $num > $count) {
                    throw new JSONException("库存不足");
                }
            }
        }
        return $this->json(200, "success");
    }

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function inventory(): array
    {
        $sharedCode = (string)$_POST['sharedCode'];//商品CODE

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

        if ($commodity->delivery_way == 0) {
            $count = Card::query()->where("commodity_id", $commodity->id)->where("status", 0)->count();
        }

        return $this->json(200, "success", ['count' => $count, 'delivery_way' => $commodity->delivery_way, "draft_status" => $commodity->draft_status]);
    }

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function trade(): array
    {
        $_POST['pay_id'] = 1; //强制走余额支付

        $commodity = \App\Model\Commodity::query()->where("code", (string)$_POST['shared_code'])->first();

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }
        $_POST['commodity_id'] = $commodity->id;

        return $this->json(200, 'success', $this->order->trade($this->getUser(), $this->getUserGroup()));
    }

    /**
     * @param int $commodityId
     * @param int $page
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function draftCard(#[Post] string $sharedCode, #[Post] int $page): array
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
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(Card::class);
        $queryTemplateEntity->setLimit(10);
        $queryTemplateEntity->setPage($page);
        $queryTemplateEntity->setWhere(["equal-commodity_id" => (string)$commodity->id, "equal-status" => 0]);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setField(['id', 'draft']);
        $data = $this->query->findTemplateAll($queryTemplateEntity)->toArray();
        return $this->json(200, null, $data);
    }

}