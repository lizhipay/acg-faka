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
        hook(Hook::USER_API_PURCHASE_RECORD_LIST, $data);
        return $this->json(data: $data);
    }

}