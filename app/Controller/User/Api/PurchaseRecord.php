<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Consts\Hook;
use App\Controller\Base\API\User;
use App\Entity\Query\Get;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Query;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class PurchaseRecord extends User
{
    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $this->request->post();
        $get = new Get(\App\Model\Order::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setOrderBy("id", "desc");
        $get->setWhere($map);
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->where("owner", $this->getUser()->id)->with([
                'commodity' => function (Relation $relation) {
                    $relation->select(["id", "name", "cover", "delivery_way", "contact_type", "leave_message"]);
                },
                'pay' => function (Relation $relation) {
                    $relation->select(["id", "name", "icon"]);
                }
            ]);
        });
        //发货留言以下单时的快照为准，老订单（3.5.9 之前）回退到商品当前留言（issue #813）
        foreach ($data['list'] as &$item) {
            //发货留言是付款后才该出现的内容（与查单页 Index::query 同口径）：未付款订单一律不下发，
            //否则会员下单不付款即可看到商家的发货留言（可能含卡密 / 下载链接 / 操作说明）。
            if ((int)($item['status'] ?? 0) !== 1) {
                unset($item['leave_message']);
                if (isset($item['commodity'])) {
                    unset($item['commodity']['leave_message']);
                }
                continue;
            }
            $resolved = \App\Model\Order::resolveLeaveMessage(
                $item['leave_message'] ?? null,
                $item['commodity']['leave_message'] ?? null
            );
            $item['leave_message'] = $resolved;
            if (isset($item['commodity'])) {
                $item['commodity']['leave_message'] = $resolved;
            }
        }
        unset($item);

        hook(Hook::USER_API_PURCHASE_RECORD_LIST, $data);
        return $this->json(data: $data);
    }

}