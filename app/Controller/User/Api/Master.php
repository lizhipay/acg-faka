<?php
declare (strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\Store;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Model\UserCategory;
use App\Model\UserCommodity;
use App\Service\Query;
use App\Util\Throttle;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

//Master 是分站主(转售主站商品)配置售价/展示的接口，此前漏了店铺门禁：任何登录用户都能写
//user_commodity/user_category 并触发 setCommodityAll* 全表遍历写。补 Store（=已开通店铺，与
//Business::saveConfig 的 businessValidation 同口径；不用 Business 是因它还要 supplier，会误伤分站主）。
#[Interceptor([Waf::class, UserSession::class, Store::class], Interceptor::TYPE_API)]
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
        //不过滤 hide：隐藏分类只是对游客不可见，配好会员等级后分站的会员照样能看到，分站主必须能配置它(#922)
        $get = new Get(\App\Model\Category::class);
        //树形表格靠 pid 拼父子关系，必须一次返回全部；分页会把父分类不在同一页的子分类整行丢掉(#922)
        $get->setWhere($map);
        $get->setOrderBy('sort', 'asc');
        $get->setColumn('id', 'icon', 'name', 'pid');
        $data = $this->query->get($get);

        $ids = array_map('intval', array_column($data['list'], 'id'));
        $userCategories = UserCategory::query()->where("user_id", $this->getUser()->id)->get()->keyBy("category_id");

        foreach ($data['list'] as &$item) {
            //上级不在列表里(被停用)的分类按顶级渲染，否则前端 treegrid 找不到父节点会把它丢掉
            if ($item['pid'] !== null && !in_array((int)$item['pid'], $ids, true)) {
                $item['pid'] = null;
            }
            $item['user_category'] = $userCategories->get((int)$item['id'])?->toArray();
        }

        return $this->json(data: $data);
    }

    /**
     * @throws JSONException
     */
    public function setCategory(): array
    {
        $map = $this->request->post(flags: Filter::NORMAL);
        $userId = $this->getUser()->id;
        $id = (int)($map['id'] ?? 0);
        $categoryId = (int)($map['category_id'] ?? 0);

        if ($id != 0) {
            if (!UserCategory::query()->where("user_id", $userId)->find($id)) {
                throw new JSONException("设置错误，请刷新网页");
            }
        } else {
            //首次设置时还没有 user_category 记录：确认是主站分类后按 (user_id, category_id) 复用已有记录，没有才新建(#922)
            if ($categoryId <= 0 || !\App\Model\Category::query()->where("owner", 0)->where("id", $categoryId)->exists()) {
                throw new JSONException("分类不存在，请刷新网页");
            }
            $id = (int)(UserCategory::query()->where("user_id", $userId)->where("category_id", $categoryId)->value("id") ?? 0);
        }

        $save = new Save(UserCategory::class);
        $save->setId($id);
        $save->setMap($map, ['name', 'status']);
        if ($id == 0) {
            //白名单会把 user_id/category_id 一起滤掉，新建记录必须强制写入，否则 NOT NULL 列让 INSERT 直接报错(#922)
            $save->addForceMap("user_id", $userId);
            $save->addForceMap("category_id", $categoryId);
        }
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
            //按 (user_id, category_id) 复用已有记录，避免 id=0 但记录已存在时 new+save 撞唯一键→500
            $userCategory = UserCategory::query()->where("user_id", $userId)->where("category_id", $categoryId)->first();
            if (!$userCategory) {
                $userCategory = new UserCategory();
                $userCategory->user_id = $userId;
                $userCategory->category_id = $categoryId;
                $userCategory->status = 0;
                $userCategory->save();
                return $this->json(200, "已生效");
            }
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
        //全表遍历写：限流防止被反复调用堆大 user_category 表 / 压 DB（写放大 DoS）
        if (Throttle::tooMany("master:bulk:" . $this->getUser()->id, 20, 60)) {
            throw new JSONException("操作过于频繁，请稍后再试");
        }
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
        $userId = $this->getUser()->id;
        $id = (int)($map['id'] ?? 0);
        $commodityId = (int)($map['commodity_id'] ?? 0);

        if ($id != 0) {
            if (!UserCommodity::query()->where("user_id", $userId)->find($id)) {
                throw new JSONException("设置错误，请刷新网页");
            }
        } else {
            //首次设置时还没有 user_commodity 记录：确认是主站商品后按 (user_id, commodity_id) 复用已有记录，没有才新建(#922)
            if ($commodityId <= 0 || !\App\Model\Commodity::query()->where("owner", 0)->where("id", $commodityId)->exists()) {
                throw new JSONException("商品不存在，请刷新网页");
            }
            $id = (int)(UserCommodity::query()->where("user_id", $userId)->where("commodity_id", $commodityId)->value("id") ?? 0);
        }

        if (isset($map['premium']) && $map['premium'] !== '') {
            if (!is_numeric($map['premium'])) {
                throw new JSONException("加价百分比必须是数字");
            }
            if ($map['premium'] < 0) {
                throw new JSONException("加价百分比，无法低于0");
            }
        }

        if (isset($map['rounding'])) {
            $map['rounding'] = (int)$map['rounding'];
            if (!in_array($map['rounding'], [UserCommodity::ROUNDING_NONE, UserCommodity::ROUNDING_ROUND, UserCommodity::ROUNDING_CEIL], true)) {
                throw new JSONException("价格取整方式不正确");
            }
        }

        $save = new Save(UserCommodity::class);
        $save->setId($id);
        //description 走的是普通 post()，WAF 已经用 HTMLPurifier 过滤过一遍，
        //分站主是普通用户不是管理员，这里刻意不像后台那样取 unsafePost 原文(#805)
        $save->setMap($map, ['name', 'description', 'premium', 'status', 'rounding']);
        if ($id == 0) {
            //白名单会把 user_id/commodity_id 一起滤掉，新建记录必须强制写入，否则 NOT NULL 列让 INSERT 直接报错(#922)
            $save->addForceMap("user_id", $userId);
            $save->addForceMap("commodity_id", $commodityId);
        }

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
            //按 (user_id, commodity_id) 复用已有记录：id=0 但该商品已配置过时，盲目 new+save 会撞唯一键→500
            $userCommodity = UserCommodity::query()->where("user_id", $userId)->where("commodity_id", $commodityId)->first();
            if (!$userCommodity) {
                $userCommodity = new UserCommodity();
                $userCommodity->user_id = $userId;
                $userCommodity->commodity_id = $commodityId;
                $userCommodity->status = 0;
                $userCommodity->save();
                return $this->json(200, "已生效");
            }
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
        //全表遍历写：限流防止被反复调用堆大 user_commodity 表 / 压 DB（写放大 DoS）
        if (Throttle::tooMany("master:bulk:" . $this->getUser()->id, 20, 60)) {
            throw new JSONException("操作过于频繁，请稍后再试");
        }
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
        //全表遍历写：限流防止被反复调用堆大 user_commodity 表 / 压 DB（写放大 DoS）
        if (Throttle::tooMany("master:bulk:" . $this->getUser()->id, 20, 60)) {
            throw new JSONException("操作过于频繁，请稍后再试");
        }
        $categoryId = (int)$_POST['category_id'];
        $premium = (int)$_POST['premium'];
        $rounding = (int)($_POST['rounding'] ?? UserCommodity::ROUNDING_NONE);

        if ($premium < 0) {
            throw new JSONException("加价百分比，无法低于0");
        }

        if (!in_array($rounding, [UserCommodity::ROUNDING_NONE, UserCommodity::ROUNDING_ROUND, UserCommodity::ROUNDING_CEIL], true)) {
            throw new JSONException("价格取整方式不正确");
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
            $userCommodity->rounding = $rounding;
            $userCommodity->save();
        }

        return $this->json(200, "加价已生效");
    }


}