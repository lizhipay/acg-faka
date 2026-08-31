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
        $map = array_intersect_key($_POST, array_flip([
            'equal-email',
            'equal-nickname',
            'equal-create_ip',
            'search-content',
            'between-create_time',
            'equal-risk',
        ]));
        $page = max(1, (int)$this->request->post('page'));
        $limit = (int)$this->request->post('limit');
        if (!in_array($limit, [15, 30, 50], true)) {
            $limit = 15;
        }
        $get = new Get(ManageLog::class);
        $get->setPaginate($page, $limit);
        $get->setWhere($map);
        $data = $this->query->get($get);
        return $this->json(data: $data);
    }
}
