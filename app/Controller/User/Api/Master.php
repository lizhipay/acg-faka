<?php
declare (strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Model\UserCategory;
use App\Model\UserCommodity;
use App\Service\Query;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

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
        $get = new Get(\App\Model\Category::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));

        $get->setWhere($map);
        $get->setOrderBy('sort', 'asc');
        $get->setColumn('id', 'icon', 'name', 'sort');
        $data = $this->query->get($get);


        foreach ($data['list'] as &$item) {
            $userCategory = UserCategory::query()->where("user_id", $this->getUser()->id)->where("category_id", $item['id'])->first();
            $item['user_category'] = $userCategory?->toArray();
        }

        return $this->json(data: $data);
    }

    /**
     * @throws JSONException
     */
    public function setCategory(): array
    {
        $map = $this->request->post(flags: Filter::NORMAL);
        $map['user_id'] = $this->getUser()->id;

        if ($map['id'] != 0) {
            if (!UserCategory::query()->where("user_id", $map['user_id'])->find($map['id'])) {
                throw new JSONException("设置错误，请刷新网页");
            }
        }

        $save = new Save(UserCategory::class);
        $save->setMap($map, ['name', 'status']);
        $save = $this->query->save($save);

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

        $get = new Get(\App\Model\Commodity::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $get->setOrderBy('sort', 'asc');
        $get->setColumn('id', 'name', 'cover', 'price', 'user_price', 'sort');
        $data = $this->query->get($get);
        foreach ($data['list'] as &$item) {
            $UserCommodity = UserCommodity::query()->where("user_id", $this->getUser()->id)->where("commodity_id", $item['id'])->first();
            $item['user_commodity'] = $UserCommodity?->toArray();
        }

        return $this->json(data: $data);
    }


    /**
     * @throws JSONException
     */
    public function setCommodity(): array
    {
        $map = $this->request->post(flags: Filter::NORMAL);
        $map['user_id'] = $this->getUser()->id;
        if ($map['id'] != 0) {
            if (!UserCommodity::query()->where("user_id", $map['user_id'])->find($map['id'])) {
                throw new JSONException("设置错误，请刷新网页");
            }
        }

        if ($map['premium'] < 0) {
            throw new JSONException("加价百分比，无法低于0");
        }

        $save = new Save(UserCommodity::class);
        $save->setMap($map, ['name', 'premium', 'status']);

        $save = $this->query->save($save);
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
     * @throws JSONException
     */
    public function setCommodityAllPremium(): array
    {
        $categoryId = (int)$_POST['category_id'];
        $premium = (int)$_POST['premium'];

        if ($premium < 0) {
            throw new JSONException("加价百分比，无法低于0");
        }

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

            $userCommodity->premium = $premium;
            $userCommodity->save();
        }

        return $this->json(200, "加价已生效");
    }


}