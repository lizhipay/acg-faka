<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Entity\Query\Get;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Query;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

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
        $map = $this->request->post();
        $get = new Get(\App\Model\User::class);
        $get->setOrderBy(...$this->query->getOrderBy($map, "id", "desc"));
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $get->setColumn("id", "pid", "username", "email", "phone", "qq", "avatar", "create_time", "status", "balance", "recharge");
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->where("pid", $this->getUser()->id);
        });
        return $this->json(data: $data);
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function transfer(): array
    {
        $to = $this->request->post("id");
        $amount = $this->request->post("amount", Filter::FLOAT);

        DB::connection()->getPdo()->exec("set session transaction isolation level serializable");
        Db::transaction(function () use ($to, $amount) {
            $userId = $this->getUser()->id;
            \App\Model\Bill::create($userId, $amount, 0, "转账给ID:{$to}", 0, false);
            \App\Model\Bill::create($to, $amount, 1, "来自ID:{$userId}的转账", 0, false);
        });

        return $this->json();
    }
}