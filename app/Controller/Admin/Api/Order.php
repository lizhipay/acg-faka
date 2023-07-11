<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\CreateObjectEntity;
use App\Entity\DeleteBatchEntity;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Service\Query;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Order extends Manage
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
        $queryTemplateEntity->setModel(\App\Model\Order::class);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setWhere($map);
        $queryTemplateEntity->setWith([
            'coupon' => function (Relation $relation) {
                $relation->select(["id", "code"]);
            },
            'owner' => function (Relation $relation) {
                $relation->select(["id", "username", "avatar", "recharge"]);
            },
            'user' => function (Relation $relation) {
                $relation->select(["id", "username", "avatar", "recharge"]);
            },
            'commodity' => function (Relation $relation) {
                $relation->select(["id", "name", "delivery_way", "contact_type"]);
            },
            'pay' => function (Relation $relation) {
                $relation->select(["id", "name", "icon"]);
            },
            'promote' => function (Relation $relation) {
                $relation->select(["id", "username", "avatar", "recharge"]);
            }
        ]);

        $query = \App\Model\Order::query();
        $data = $this->query->findTemplateAll($queryTemplateEntity, $query)->toArray();
        $json = $this->json(200, null, $data['data']);
        $json['count'] = $data['total'];

        //获取订单数量
        $json['order_count'] = (clone $query)->count();
        $json['order_amount'] = (clone $query)->sum("amount");
        $json['order_cost'] = (clone $query)->sum("cost");

        return $json;
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function save(): array
    {
        $map = $_POST;
        if (!$map['secret']) {
            throw new JSONException("请填写要发货的内容");
        }
        $createObjectEntity = new CreateObjectEntity();
        $createObjectEntity->setModel(\App\Model\Order::class);
        $createObjectEntity->setMap(['id' => (int)$map['id'], 'secret' => $map['secret'], 'delivery_status' => 1]);
        $save = $this->query->createOrUpdateTemplate($createObjectEntity);
        if (!$save) {
            throw new JSONException("发货失败");
        }

        ManageLog::log($this->getManage(), "[手动发货]({$map['id']})修改了发货信息");
        return $this->json(200, '（＾∀＾）发货成功');
    }


    /**
     * @return array
     */
    public function clear(): array
    {
        \App\Model\Order::query()
            ->where("create_time", "<", date("Y-m-d H:i:s", time() - 1800))
            ->where("status", 0)->delete();

        ManageLog::log($this->getManage(), "进行了一键清理无用商品订单操作");
        return $this->json(200, '（＾∀＾）清理完成');
    }
}