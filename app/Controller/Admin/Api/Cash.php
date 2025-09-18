<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Service\Query;
use App\Util\Date;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        $get = new Get(\App\Model\Cash::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->with([
                'user' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar", "nicename", "alipay", "wechat"]);
                }
            ]);
        });

        return $this->json(data: $data);
    }

    /**
     * @return array
     * @throws JSONException
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