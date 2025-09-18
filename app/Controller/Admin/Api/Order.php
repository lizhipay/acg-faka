<?php

declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Service\Query;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

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
        $get = new Get(\App\Model\Order::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $raw = [];
        $data = $this->query->get($get, function (Builder $builder) use (&$raw) {
            $raw['order_amount'] = (clone $builder)->sum("amount");
            $raw['order_cost'] = (clone $builder)->sum("cost");
            return $builder->with([
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
                    $relation->select(["id", "name", "cover", "delivery_way", "contact_type"]);
                },
                'pay' => function (Relation $relation) {
                    $relation->select(["id", "name", "icon"]);
                },
                //推广者
                'promote' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar", "recharge"]);
                },
                //分站订单
                'substationUser' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar", "recharge"]);
                },
                'card'
            ]);
        });

        return $this->json(data: array_merge($data, $raw));
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function save(): array
    {
        $map = $this->request->post(flags: Filter::NORMAL);
        if (!$map['secret']) {
            throw new JSONException("请填写要发货的内容");
        }
        $save = new Save(\App\Model\Order::class);
        $save->setMap(['id' => (int)$map['id'], 'secret' => $map['secret'], 'delivery_status' => 1]);
        $save = $this->query->save($save);
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
