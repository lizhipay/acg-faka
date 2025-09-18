<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Entity\Query\Get;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Query;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, UserSession::class, \App\Interceptor\Business::class], Interceptor::TYPE_API)]
class CommodityOrder extends User
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
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->where("user_id", $this->getUser()->id)->with([
                'coupon' => function (Relation $relation) {
                    $relation->select(["id", "code"]);
                },
                'owner' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar"]);
                },
                'commodity' => function (Relation $relation) {
                    $relation->select(["id", "name", "cover", "delivery_way", "contact_type"]);
                },
                'pay' => function (Relation $relation) {
                    $relation->select(["id", "name", "icon"]);
                },
                'card'
            ]);
        });
        return $this->json(data: $data);
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function delivery(): array
    {
        $id = (int)$_POST['id'];
        $message = $_POST['secret'];

        if (!$message) {
            throw new JSONException("发货内容不能为空");
        }

        $order = \App\Model\Order::query()->where("user_id", $this->getUser()->id)->find($id);

        if (!$order) {
            throw new JSONException("要发货的订单不存在");
        }

        if ($order->status == 0) {
            throw new JSONException("该订单还未支付");
        }

        $order->secret = $message;
        $order->delivery_status = 1;
        $order->save();

        return $this->json(200, "发货成功");
    }
}