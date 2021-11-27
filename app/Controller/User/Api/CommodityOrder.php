<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Query;
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
        $map['equal-user_id'] = $this->getUser()->id;
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\Order::class);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setWhere($map);
        $queryTemplateEntity->setWith([
            'owner' => function (Relation $relation) {
                $relation->select(["id", "username", "avatar"]);
            },
            'commodity' => function (Relation $relation) {
                $relation->select(["id", "name", "delivery_way", "contact_type"]);
            },
            'pay' => function (Relation $relation) {
                $relation->select(["id", "name", "icon"]);
            }
        ]);
        $data = $this->query->findTemplateAll($queryTemplateEntity)->toArray();
        $json = $this->json(200, null, $data['data']);
        $json['count'] = $data['total'];
        return $json;
    }


    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
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

        if ($order->delivery_status == 1) {
            throw new JSONException("该订单已发货过了");
        }

        $order->secret = $message;
        $order->delivery_status = 1;
        $order->save();

        return $this->json(200, "发货成功");
    }
}