<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Service\Query;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\NotFoundException;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Bill extends Manage
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
        $get = new Get(\App\Model\Bill::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->with([
                'owner' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar"]);
                }
            ]);
        });
        return $this->json(data: $data);
    }
}