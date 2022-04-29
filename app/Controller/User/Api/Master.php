<?php
declare (strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Entity\CreateObjectEntity;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Model\UserCategory;
use App\Model\UserCommodity;
use App\Service\Query;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class Master extends User
{
    #[Inject]
    private Query $query;

    #[Inject]
    private \App\Service\Order $order;

    /**
     * 获取主站分类
     * @return array
     */
    public function category(): array
    {
        $map = [];
        $map['equal-status'] = 1;
        $map['equal-owner'] = 0;
        $map['equal-hide'] = 0;
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\Category::class);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setWhere($map);
        $queryTemplateEntity->setOrder('sort', 'asc');
        $queryTemplateEntity->setField(['id', 'name']);
        $data = $this->query->findTemplateAll($queryTemplateEntity)->toArray();
        $array = $data['data'];

        foreach ($array as $index => $item) {
            $userCategory = UserCategory::query()->where("user_id", $this->getUser()->id)->where("category_id", $item['id'])->first();
            if ($userCategory) {
                $array[$index]['user_category'] = $userCategory->toArray();
            } else {
                $array[$index]['user_category'] = null;
            }
        }

        $json = $this->json(200, null, $array);
        $json['count'] = $data['total'];
        return $json;
    }

    /**
     * @throws JSONException
     */
    public function setCategory(): array
    {
        $map = $_POST;
        $map['user_id'] = $this->getUser()->id;

        if ($map['id'] != 0) {
            if (!UserCategory::query()->where("user_id", $map['user_id'])->find($map['id'])) {
                throw new JSONException("设置错误，请刷新网页");
            }
        }

        $createObjectEntity = new CreateObjectEntity();
        $createObjectEntity->setModel(\App\Model\UserCategory::class);
        $createObjectEntity->setMap($map, ['name']);
        $save = $this->query->createOrUpdateTemplate($createObjectEntity);
        if (!$save) {
            throw new JSONException("保存失败");
        }
        return $this->json(200, '（＾∀＾）配置已生效');
    }

    /**
     * @return array
     */
    public function setCategoryStatus(): array
    {
        $id = (int)$_POST['id'];
        $categoryId = (int)$_POST['category_id'];
        $userId = $this->getUser()->id;

        if ($id == 0 || !($userCategory = UserCategory::query()->where("user_id", $userId)->find($id))) {
            $userCategory = new UserCategory();
            $userCategory->user_id = $userId;
            $userCategory->category_id = $categoryId;
            $userCategory->status = 0;
            $userCategory->save();
            return $this->json(200, "已生效");
        }

        $userCategory->status = $userCategory->status == 0 ? 1 : 0;
        $userCategory->save();
        return $this->json(200, "已生效");
    }

    /**
     * @return array
     */
    public function setCategoryAllStatus(): array
    {
        $status = (int)$_POST['status'] == 0 ? 0 : 1;
        $category = \App\Model\Category::query()->where("owner", 0)->where("status", 1)->get();

        $userId = $this->getUser()->id;
        foreach ($category as $item) {
            $userCategory = UserCategory::query()->where("user_id", $userId)->where("category_id", $item['id'])->first();
            if (!$userCategory) {
                $userCategory = new UserCategory();
                $userCategory->category_id = $item['id'];
                $userCategory->user_id = $userId;
            }
            $userCategory->status = $status;
            $userCategory->save();
        }
        return $this->json(200, "已生效");
    }

    /**
     * @return array
     */
    public function commodity(): array
    {
        $map = [];
        $map['equal-status'] = 1;
        $map['equal-owner'] = 0;
        $map['equal-hide'] = 0;

        $categoryId = (int)$_POST['category_id'];

        if ($categoryId) {
            $map['equal-category_id'] = $categoryId;
        }

        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\Commodity::class);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setWhere($map);
        $queryTemplateEntity->setOrder('sort', 'asc');
        $data = $this->query->findTemplateAll($queryTemplateEntity);


        $items = $data->items();
        $array = [];

        $user = $this->getUser();
        $userGroup = $this->getUserGroup();
        foreach ($items as $index => $item) {
            $userCategory = UserCommodity::query()->where("user_id", $this->getUser()->id)->where("commodity_id", $item['id'])->first();
            if ($userCategory) {
                $array[$index]['user_commodity'] = $userCategory->toArray();
            } else {
                $array[$index]['user_commodity'] = null;
            }
            //计算拿货价，采用新的方式
            $tradeAmount = $this->order->calcAmount(
                owner: $user->id,
                num: 1,
                commodity: $item,
                group: $userGroup,
                disableSubstation: true
            );
            $array[$index]['user_price'] = $tradeAmount;
            $array[$index]['price'] = $item->price;
            $array[$index]['id'] = $item->id;
            $array[$index]['name'] = $item->name;
        }
        $json = $this->json(200, null, $array);
        $json['count'] =  $data->total();
        return $json;
    }


    /**
     * @throws JSONException
     */
    public function setCommodity(): array
    {
        $map = $_POST;
        $map['user_id'] = $this->getUser()->id;
        if ($map['id'] != 0) {
            if (!UserCommodity::query()->where("user_id", $map['user_id'])->find($map['id'])) {
                throw new JSONException("设置错误，请刷新网页");
            }
        }
        $createObjectEntity = new CreateObjectEntity();
        $createObjectEntity->setModel(\App\Model\UserCommodity::class);
        $createObjectEntity->setMap($map, ['name']);
        $save = $this->query->createOrUpdateTemplate($createObjectEntity);
        if (!$save) {
            throw new JSONException("保存失败");
        }
        return $this->json(200, '（＾∀＾）配置已生效');
    }

    /**
     * @return array
     */
    public function setCommodityStatus(): array
    {
        $id = (int)$_POST['id'];
        $commodityId = (int)$_POST['commodity_id'];
        $userId = $this->getUser()->id;

        if ($id == 0 || !($userCommodity = UserCommodity::query()->where("user_id", $userId)->find($id))) {
            $userCommodity = new UserCommodity();
            $userCommodity->user_id = $userId;
            $userCommodity->commodity_id = $commodityId;
            $userCommodity->status = 0;
            $userCommodity->save();
            return $this->json(200, "已生效");
        }

        $userCommodity->status = $userCommodity->status == 0 ? 1 : 0;
        $userCommodity->save();
        return $this->json(200, "已生效");
    }


    /**
     * @return array
     */
    public function setCommodityAllStatus(): array
    {
        $status = (int)$_POST['status'] == 0 ? 0 : 1;
        $categoryId = (int)$_POST['category_id'];
        $commodity = \App\Model\Commodity::query()->where("owner", 0)->where("status", 1);

        if ($categoryId != 0) {
            $commodity->where("category_id", $categoryId);
        }

        $commodity = $commodity->get();

        $userId = $this->getUser()->id;
        foreach ($commodity as $item) {
            $userCommodity = UserCommodity::query()->where("user_id", $userId)->where("commodity_id", $item['id'])->first();
            if (!$userCommodity) {
                $userCommodity = new UserCommodity();
                $userCommodity->commodity_id = $item['id'];
                $userCommodity->user_id = $userId;
            }
            $userCommodity->status = $status;
            $userCommodity->save();
        }

        return $this->json(200, "已生效");
    }

    /**
     * @return array
     */
    public function setCommodityAllPremium(): array
    {
        $mode = (int)$_POST['mode'] == 0 ? 0 : 1;
        $categoryId = (int)$_POST['category_id'];
        $premium = (float)$_POST['premium'];

        $commodity = \App\Model\Commodity::query()->where("owner", 0)->where("status", 1);

        if ($categoryId != 0) {
            $commodity->where("category_id", $categoryId);
        }

        $commodity = $commodity->get();
        $userId = $this->getUser()->id;
        foreach ($commodity as $item) {
            $userCommodity = UserCommodity::query()->where("user_id", $userId)->where("commodity_id", $item->id)->first();
            if (!$userCommodity) {
                $userCommodity = new UserCommodity();
                $userCommodity->commodity_id = $item['id'];
                $userCommodity->user_id = $userId;
            }

            if ($mode == 0) {
                $userCommodity->premium = $premium;
            } else {
                $userCommodity->premium = $item->price * $premium;
            }

            $userCommodity->save();
        }

        return $this->json(200, "加价已生效");
    }


}