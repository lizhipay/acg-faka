<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\CreateObjectEntity;
use App\Entity\DeleteBatchEntity;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Model\Bill;
use App\Model\Business;
use App\Model\UserGroup;
use App\Service\Query;
use App\Util\Date;
use App\Util\Str;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class User extends Manage
{
    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\User::class);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setWhere($map);
        $queryTemplateEntity->setWith([
            'parent' => function (Relation $relation) {
                $relation->select(["id", "username", "avatar"]);
            },
            'businessLevel',
            'business'
        ]);
        $data = $this->query->findTemplateAll($queryTemplateEntity)->toArray();
        $json = $this->json(200, null, $data['data']);
        $json['count'] = $data['total'];
        return $json;
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function save(): array
    {
        $map = $_POST;

        unset($map['balance']);
        unset($map['coin']);

        $user = \App\Model\User::query()->find((int)$map['id']);

        if (!$user) {
            throw new JSONException("该用户不存在");
        }

        if ($map['password']) {
            if (strlen($map['password']) < 6) {
                throw new JSONException("密码必须6位以上");
            }
            $user = \App\Model\User::query()->find($map['id']);
            if (!$user) {
                throw new JSONException("用户不存在");
            }
            $map['password'] = Str::generatePassword($map['password'], $user->salt);
        }

        if ((int)$map['business_level'] > 0) {
            $level = \App\Model\BusinessLevel::query()->find((int)$map['business_level']);
            if (!$level) {
                throw new JSONException("该商户等级不存在");
            }
            //新建店铺
            if (!\App\Model\Business::query()->where("user_id", $user->id)->first()) {
                $business = new \App\Model\Business();
                $business->user_id = $user->id;
                $business->create_time = Date::current();
                $business->master_display = 1;
                $business->save();
            }
        }

        $createObjectEntity = new CreateObjectEntity();
        $createObjectEntity->setModel(\App\Model\User::class);
        $createObjectEntity->setMap($map);
        $save = $this->query->createOrUpdateTemplate($createObjectEntity);
        if (!$save) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }
        return $this->json(200, '（＾∀＾）保存成功');
    }

    /**
     * @throws \Kernel\Exception\JSONException
     */
    public function recharge(): array
    {
        $map = $_POST;
        $user = \App\Model\User::query()->find($map['id']);

        if ((float)$map['amount'] == 0) {
            throw new JSONException("操作金额不能为0");
        }

        if (isset($map['log']) && mb_strlen($map['log']) < 2) {
            throw new JSONException("原因最低不能少于2个字");
        }
        if (!$user) {
            throw new JSONException("用户不存在");
        }

        Bill::create($user, (float)$map['amount'], (int)$map['action'], $map['log'], 0, (bool)$map['total']);
        return $this->json(200, "操作成功");
    }


    /**
     * @return array
     */
    public function statistics(): array
    {
        $userId = $_GET['id'];
        $order = \App\Model\Order::query()->where("user_id", $userId)->where("status", 1);
        $data = [];
        //今日交易
        $data['today_order_amount'] = sprintf("%.2f", (clone $order)->whereBetween('create_time', [Date::calcDay(), Date::calcDay(1)])->sum("amount"));
        //昨日交易
        $data['yesterday_order_amount'] = sprintf("%.2f", (clone $order)->whereBetween('create_time', [Date::calcDay(-1), Date::calcDay()])->sum("amount"));
        //本周交易
        $data['week_order_amount'] = sprintf("%.2f", (clone $order)->whereBetween('create_time', [Date::weekDay(1, Date::TYPE_START), Date::weekDay(7, Date::TYPE_END)])->sum("amount"));
        //本月交易
        $data['month_order_amount'] = sprintf("%.2f", (clone $order)->whereBetween('create_time', [date("Y-m-01 00:00:00"), Date::calcDay()])->sum("amount"));
        //全部交易
        $data['total_order_amount'] = sprintf("%.2f", (clone $order)->sum("amount"));

        return $this->json(200, "success", $data);
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {
        $deleteBatchEntity = new DeleteBatchEntity();
        $deleteBatchEntity->setModel(\App\Model\User::class);
        $deleteBatchEntity->setList($_POST['list']);
        $count = $this->query->deleteTemplate($deleteBatchEntity);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }

        $list = (array)$_POST['list'];

        //删除店铺
        foreach ($list as $id) {
            Business::query()->where("user_id", $id)->delete();
        }

        return $this->json(200, '（＾∀＾）移除成功');
    }
}