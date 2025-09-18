<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Entity\Query\Get;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Query;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class Bill extends User
{
    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $this->request->post();
        $get = new Get(\App\Model\Bill::class);
        $get->setOrderBy(...$this->query->getOrderBy($map, "id", "desc"));
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->where("owner", $this->getUser()->id);
        });
        return $this->json(data: $data);
    }
}