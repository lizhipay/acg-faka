<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Model\UserGroup;
use App\Service\Query;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Exception\NotFoundException;
use Kernel\Exception\RuntimeException;
use Kernel\Waf\Filter;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Group extends Manage
{
    #[Inject]
    private Query $query;

    /**
     * @return array
     * @throws NotFoundException
     * @throws \ReflectionException
     */
    public function data(): array
    {
        $map = $_POST;
        $get = new Get(UserGroup::class);
        $get->setWhere($map);
        $get->setOrderBy("recharge", "desc");
        $data = $this->query->get($get);
        return $this->json(data: $data);
    }

    /**
     * @param int $id
     * @return array
     * @throws JSONException
     */
    public function commodityGroupData(int $id): array
    {
        $group = UserGroup::query()->find($id);

        if (!$group) {
            throw new JSONException("会员等级不存在");
        }

        $discountConfig = $group?->discount_config ?: [];

        $collection = \App\Model\CommodityGroup::query()->get(["id", "name"]);
        $data = $collection->toArray();


        foreach ($data as &$item) {
            if (isset($discountConfig[$item['id']])) {
                $item['value'] = $discountConfig[$item['id']];
            } else {
                $item['value'] = 100;
            }
        }

        return $this->json(200, data: $data);
    }


    /**
     * @return array
     * @throws JSONException
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws \ReflectionException
     */
    public function save(): array
    {
        $map = $_POST;
        $save = new Save(UserGroup::class);
        $save->setMap($map);
        $save = $this->query->save($save);
        if (!$save) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }

        ManageLog::log($this->getManage(), "[新增/修改]会员等级");
        return $this->json(200, '（＾∀＾）保存成功');
    }

    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function setDiscountConfig(Request $request): array
    {
        $groupId = $request->post("group_id", Filter::INTEGER);
        $id = $request->post("id", Filter::INTEGER);
        $value = $request->post("value", Filter::FLOAT);
        if (!$groupId || !$id) {
            throw new JSONException("参数不全");
        }
        $group = UserGroup::query()->find($groupId);

        if (!$group) {
            throw new JSONException("未找到该会员等级");
        }

        if ($value < 0) {
            throw new JSONException("折扣不能小于0%");
        }

        $discountConfig = $group->discount_config ?: [];
        $discountConfig[$id] = $value;

        $group->discount_config = $discountConfig;
        $group->save();

        return $this->json();
    }


    /**
     * @return array
     * @throws JSONException
     * @throws NotFoundException
     * @throws \ReflectionException
     */
    public function del(): array
    {
        $delete = new Delete(UserGroup::class, [$_POST['id']]);
        $count = $this->query->delete($delete);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }
        ManageLog::log($this->getManage(), "[删除]会员等级");
        return $this->json(200, '（＾∀＾）移除成功');
    }
}