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
        $userId = $this->getUser()->id;

        // --- 核心安全修復：攔截非法金額（徹底堵死負數刷餘額） ---
        if ($amount <= 0) {
            throw new JSONException("非法操作：轉帳金額必須大於 0！");
        }

        // --- 核心安全修復：禁止自己給自己轉帳 ---
        if ($to == $userId) {
            throw new JSONException("非法操作：不能給自己轉帳！");
        }

        // --- 核心安全修復：移除導致數據庫死鎖的串行化鎖表代碼 ---
        // DB::connection()->getPdo()->exec("set session transaction isolation level serializable");
        
        Db::transaction(function () use ($to, $amount, $userId) {
            \App\Model\Bill::create($userId, $amount, 0, "轉帳給ID:{$to}", 0, false);
            \App\Model\Bill::create($to, $amount, 1, "來自ID:{$userId}的轉帳", 0, false);
        });

        return $this->json();
    }
}
