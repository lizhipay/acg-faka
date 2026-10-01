<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Entity\Query\Get;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Model\Config;
use App\Service\Query;
use App\Util\Client;
use App\Util\Date;
use App\Util\Throttle;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Exception\RuntimeException;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class Cash extends User
{

    #[Inject]
    private Query $query;

    /**
     * @return array
     * @throws JSONException
     * @throws RuntimeException
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

        //资金操作二次验证：开启了两步验证并打开开关的会员，提现前需通过 TOTP（步进窗口内免重复）
        \App\Util\FundGuard::assert($u);

        //提现频率闸（纵深防御）：正常用户不会高频提现，挡住脚本化并发刷单。
        //真正的并发一致性靠下方事务的 serializable + lockForUpdate 保证，这里只做限速。
        if (Throttle::tooMany("cash:submit:" . $u->id . ":" . Client::getAddress(), 10, 60)) {
            throw new JSONException("兑现操作过于频繁，请稍后再试");
        }

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
        } elseif ($type == 3) {
            if (Config::get("cash_type_usdt") != 1) {
                throw new JSONException("未启用USDT兑换");
            }
            if ($u->wallet_address == "") {
                throw new JSONException("您还没有绑定钱包地址");
            }
        }

        //风控判决。提现是资损真正兑现的地方 —— 注册一个空账号几乎没有价值，
        //危害要到把钱提走这一步才落地，所以真正的闸门在这里。
        $riskMap = ['type' => $type, 'amount' => $amount, 'cost' => $cashCost];
        $risk = new \App\Entity\RiskContext('cash', $riskMap);
        hook(\App\Consts\Hook::USER_API_CASH_SUBMIT_BEGIN, $risk, $u, $riskMap);
        if ($risk->denied()) {
            throw new JSONException($risk->message("该笔兑现暂时无法提交，请联系客服"));
        }

        $userId = $u->id;
        //挂人工审核不需要发明新状态：cash.status = 0 本来就是「待站长处理」，
        //只有 type==2（兑现到可消费余额）会自动到账，挂起时把这条捷径关掉即可。
        $status = ($type == 2 && !$risk->held()) ? 1 : 0;
        //与下单/转账/充值回调同构：串行化隔离 + 事务内对会员行 lockForUpdate。
        //Bill::create 是「读余额→加减→写回」的非原子操作，缺了这两道锁，并发提现会各自读到同一份
        //旧硬币余额、各自写回（丢失更新），造成一次扣款生成多条兑现记录（外部打款类型即重复出账盗款）。
        DB::connection()->getPdo()->exec("set session transaction isolation level serializable");
        Db::transaction(function () use ($amount, $userId, $cashCost, $type, $status) {
            //锁定会员行：并发的第二笔请求会阻塞到本事务提交后再读到已扣减的余额，
            //Bill::create 里的 balance<0 守卫随即把它挡成「硬币不足」。
            $user = \App\Model\User::query()->lockForUpdate()->find($userId);
            if (!$user) {
                throw new JSONException("用户不存在");
            }
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
                //将硬币转给用户余额：复用同一把锁下的 $user 实例，避免用陈旧的会话对象把扣减写花。
                \App\Model\Bill::create($user, $cash->amount, 1, "硬币兑现到钱包", 0, true);
            }

            $cash->save();
        });

        \App\Model\UserLog::write($u, 'cash', '申请兑现：金额 ' . $amount);
        return $this->json(200, "兑现成功");
    }


    /**
     * @return array
     */
    public function record(): array
    {
        $map = $this->request->post();
        $get = new Get(\App\Model\Cash::class);
        $get->setOrderBy("id", "desc");
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->where("user_id", $this->getUser()->id);
        });
        return $this->json(data: $data);
    }
}