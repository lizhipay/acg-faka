<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Query;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class AgentMember extends User
{
    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $map['equal-pid'] = $this->getUser()->id;
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\User::class);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setWhere($map);
        $queryTemplateEntity->setField(["id", "pid", "username", "email", "phone", "qq", "avatar", "create_time", "status", "balance", "recharge"]);
        $data = $this->query->findTemplateAll($queryTemplateEntity)->toArray();
        $json = $this->json(200, null, $data['data']);
        $json['count'] = $data['total'];
        return $json;
    }
}