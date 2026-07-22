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
                    $relation->select(["id", "username", "avatar", "nicename", "alipay", "wechat", "wallet_address"]);
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
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException('提现处理只接受 POST 请求');
        }
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $status = filter_var($_POST['status'] ?? null, FILTER_VALIDATE_INT);
        $rawMessage = $_POST['message'] ?? '';
        if (!is_scalar($rawMessage) && $rawMessage !== null) {
            throw new JSONException('请求参数不正确');
        }
        $message = trim((string)$rawMessage);

        if ($id === false || $id <= 0 || $status === false || !in_array($status, [0, 1], true)) {
            throw new JSONException("请求参数不正确");
        }
        if ($status === 1 && $message === '') {
            throw new JSONException("请输入驳回理由");
        }
        if (mb_strlen($message) > 64) {
            throw new JSONException('驳回理由不能超过 64 个字');
        }

        DB::transaction(function () use ($message, $status, $id) {
            $cash = \App\Model\Cash::query()->lockForUpdate()->find($id);
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
                $user = \App\Model\User::query()->lockForUpdate()->find($cash->user_id);
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
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException('自动结算只接受 POST 请求');
        }
        $rawAmount = $_POST['amount'] ?? null;
        if (!is_numeric($rawAmount)) {
            throw new JSONException("请输入有效的最低结算金额");
        }
        $amount = (float)$rawAmount;
        if (!is_finite($amount) || $amount <= 0) {
            throw new JSONException("最低结算金额必须大于 0");
        }
        $this->cash->settlement($amount);

        ManageLog::log($this->getManage(), "进行了一键自动结算，金额：" . $amount);
        return $this->json(200, "结算完成");
    }

}
