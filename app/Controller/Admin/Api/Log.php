<?php
declare (strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Interceptor\Owner;
use App\Model\ManageLog;
use App\Service\Query;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;

#[Interceptor([ManageSession::class, Owner::class], Interceptor::TYPE_API)]
class Log extends Manage
{
    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $get = new Get(ManageLog::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($_POST);
        $data = $this->query->get($get);
        return $this->json(data: $data);
    }
}