<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Model\Config;
use App\Service\Query;
use App\Util\Date;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Illuminate\Database\Capsule\Manager as DB;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class Cash extends User
{

    #[Inject]
    private Query $query;

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function submit(): array
    {
        $type = (int)$_POST['type'];
        $amount = (float)$_POST['amount'];

        if ($amount <= 0) {
            throw new JSONException("请输入要兑现的金额");
        }

        $cashMin = (float)Config::get("cash_min");

        if ($amount < $cashMin) {
            throw new JSONException("最低兑现金额为{$cashMin}");
        }

        $cashCost = (float)Config::get("cash_cost");

        $u = $this->getUser();

        if ($type == 0) {
            if ($u->alipay == "") {
                throw new JSONException("您还没有绑定支付宝");
            }
        } elseif ($type == 1) {
            if ($u->wechat == "") {
                throw new JSONException("您还没有绑定微信");
            }
        }

        if ($cashCost == $amount) {
            throw new JSONException("兑现金额必须高于手续费哦");
        }

        $userId = $u->id;
        Db::transaction(function () use ($amount, $userId, $cashCost, $type) {
            $user = \App\Model\User::query()->find($userId);
            \App\Model\Bill::create($user, $amount, \App\Model\Bill::TYPE_SUB, "兑现", 1);
            $cash = new \App\Model\Cash();
            $cash->user_id = $userId;
            $cash->amount = $amount - $cashCost;
            $cash->type = 1;
            $cash->card = $type;
            $cash->create_time = Date::current();
            $cash->cost = $cashCost;
            $cash->status = 0;
            $cash->save();
        });

        return $this->json(200, "兑现成功");
    }


    /**
     * @return array
     */
    public function record(): array
    {
        $map = $_POST;
        $map['equal-user_id'] = $this->getUser()->id;
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\Cash::class);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setWhere($map);
        $data = $this->query->findTemplateAll($queryTemplateEntity)->toArray();
        $json = $this->json(200, null, $data['data']);
        $json['count'] = $data['total'];
        return $json;
    }
}