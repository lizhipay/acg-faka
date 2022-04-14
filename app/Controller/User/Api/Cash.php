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
        $cashCost = (float)Config::get("cash_cost");

        if ($amount < $cashMin) {
            throw new JSONException("最低兑现金额为{$cashMin}");
        }

        if ($amount <= $cashCost) {
            throw new JSONException("最低兑现金额必须大于{$cashCost}");
        }


        $u = $this->getUser();

        if ($type == 0) {

            if (Config::get("cash_type_alipay") != 1) {
                throw new JSONException("未启用支付宝兑现");
            }

            if ($u->alipay == "") {
                throw new JSONException("您还没有绑定支付宝");
            }
        } elseif ($type == 1) {
            if (Config::get("cash_type_wechat") != 1) {
                throw new JSONException("未启用微信兑现");
            }
            if ($u->wechat == "") {
                throw new JSONException("您还没有绑定微信");
            }
        } elseif ($type == 2) {

            if (Config::get("cash_type_balance") != 1) {
                throw new JSONException("未启用兑现到可消费余额");
            }

        }

        $userId = $u->id;
        $status = $type == 2 ? 1 : 0;
        Db::transaction(function () use ($amount, $userId, $cashCost, $type, $status, $u) {
            $user = \App\Model\User::query()->find($userId);
            \App\Model\Bill::create($user, $amount, \App\Model\Bill::TYPE_SUB, "兑现", 1);
            $cash = new \App\Model\Cash();
            $cash->user_id = $userId;
            $cash->amount = $amount - $cashCost;
            $cash->type = 1;
            $cash->card = $type;
            $cash->create_time = Date::current();
            $cash->cost = $cashCost;
            $cash->status = $status;

            if ($cash->status == 1) {
                $cash->arrive_time = Date::current();
                //将硬币转给用户余额
                \App\Model\Bill::create($u, $cash->amount, 1, "硬币兑现到钱包", 0, true);
            }

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