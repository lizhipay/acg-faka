<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Service\Query;
use App\Util\Date;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Cash extends Manage
{
    #[Inject]
    private Query $query;

    #[Inject]
    private \App\Service\Cash $cash;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\Cash::class);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setWhere($map);
        $queryTemplateEntity->setWith([
            'user' => function (Relation $relation) {
                $relation->select(["id", "username", "avatar", "nicename", "alipay", "wechat"]);
            }
        ]);
        $data = $this->query->findTemplateAll($queryTemplateEntity)->toArray();
        $json = $this->json(200, null, $data['data']);
        $json['count'] = $data['total'];
        return $json;
    }

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function decide(): array
    {
        $id = (int)$_POST['id'];
        $status = (int)$_POST['status'];
        $message = (string)$_POST['message'];

        DB::transaction(function () use ($message, $status, $id) {
            $cash = \App\Model\Cash::query()->find($id);
            if (!$cash) {
                throw new JSONException("该记录不存在");
            }

            if ($cash->status != 0) {
                throw new JSONException("该记录无法操作");
            }

            $cash->arrive_time = Date::current();

            if ($status == 0) {
                $cash->status = 1;
                $cash->save();
                ManageLog::log($this->getManage(), "通过了用户ID($cash->user_id)的提现");
            } else {
                $cash->status = 2;
                $cash->message = $message;
                $cash->save();
                $user = $cash->user;
                if ($user instanceof \App\Model\User) {
                    //驳回钱款
                    \App\Model\Bill::create($user, $cash->amount + (float)$cash->cost, \App\Model\Bill::TYPE_ADD, "兑现被拒绝", 1);
                    ManageLog::log($this->getManage(), "驳回了用户($user->username)的提现");
                }
            }
        });

        return $this->json(200, "处理成功");

    }


    /**
     * @return array
     */
    public function settlement(): array
    {
        $amount = (float)$_POST['amount'];
        $this->cash->settlement($amount);

        ManageLog::log($this->getManage(), "进行了一键自动结算，金额：" . $amount);
        return $this->json(200, "结算完成");
    }

}