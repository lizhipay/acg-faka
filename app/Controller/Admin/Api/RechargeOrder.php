<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Model\UserRecharge;
use App\Service\Query;
use App\Service\Recharge;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class RechargeOrder extends Manage
{
    #[Inject]
    private Query $query;

    #[Inject]
    private Recharge $recharge;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\UserRecharge::class);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setWhere($map);
        $queryTemplateEntity->setWith([
            'user' => function (Relation $relation) {
                $relation->select(["id", "username", "avatar"]);
            },
            'pay' => function (Relation $relation) {
                $relation->select(["id", "name", "icon"]);
            }
        ]);

        $query = \App\Model\Order::query();
        $data = $this->query->findTemplateAll($queryTemplateEntity, $query)->toArray();
        $json = $this->json(200, null, $data['data']);
        $json['count'] = $data['total'];

        //获取订单数量
        $json['order_count'] = (clone $query)->count();
        $json['order_amount'] = (clone $query)->sum("amount");
        return $json;
    }


    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function success(): array
    {
        $id = (int)$_POST['id'];
        $order = UserRecharge::query()->find($id);
        if (!$order) {
            throw new JSONException("订单不存在");
        }

        if ($order->status != 0) {
            throw new JSONException("该订单已支付，无法再进行操作了。");
        }

        $this->recharge->orderSuccess($order);

        ManageLog::log($this->getManage(), "充值订单->手动补单，订单号：{$order->trade_no}");
        return $this->json(200, "已手动确认");
    }


    /**
     * @return array
     */
    public function clear(): array
    {
        \App\Model\UserRecharge::query()
            ->where("create_time", "<", date("Y-m-d H:i:s", time() - 1800))
            ->where("status", 0)->delete();

        ManageLog::log($this->getManage(), "充值订单->一键清理无用订单");
        return $this->json(200, '（＾∀＾）清理完成');
    }
}