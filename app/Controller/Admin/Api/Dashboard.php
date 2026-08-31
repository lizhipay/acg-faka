<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Model\Business;
use App\Model\UserRecharge;
use App\Util\Date;
use Kernel\Annotation\Interceptor;
use Kernel\Util\Decimal;

#[Interceptor(\App\Interceptor\ManageSession::class, Interceptor::TYPE_API)]
class Dashboard extends \App\Controller\Base\API\Manage
{
    /**
     * @param int $type
     * @return array
     */
    public function data(int $type): array
    {
        $data = [];
        //今日
        if ($type == 0) {
            $time = [Date::calcDay(), Date::calcDay(1)];
        } elseif ($type == 1) {
            $time = [Date::calcDay(-1), Date::calcDay()];
        } elseif ($type == 2) {
            $time = [Date::weekDay(1, Date::TYPE_START), Date::weekDay(7, Date::TYPE_END)];
        } elseif ($type == 3) {
            $time = [date("Y-m-01 00:00:00"), Date::calcDay()];
        }

        if ($type == 4) {
            $order = \App\Model\Order::query()->where("status", 1);
            $business = Business::query();
            $cash = \App\Model\Cash::query();
            $user = \App\Model\User::query();
            $recharge = UserRecharge::query();
            //新注册用户数量
            $data['user_register_num'] = (clone $user)->count();
            //打卡用户
            //  $data['user_login_num'] = (clone $user)->count();

        } else {
            //init
            $order = \App\Model\Order::query()->whereBetween('create_time', $time)->where("status", 1);
            $business = Business::query()->whereBetween("create_time", $time);
            $cash = \App\Model\Cash::query()->whereBetween("create_time", $time);
            $user = \App\Model\User::query();
            $recharge = UserRecharge::query()->whereBetween("create_time", $time);

            //新注册用户数量
            $data['user_register_num'] = (clone $user)->whereBetween("create_time", $time)->count();
            //打卡用户
            // $data['user_login_num'] = (clone $user)->whereBetween("login_time", $time)->count();
        }

        //全站营业额
        $data['turnover'] = sprintf("%.2f", (clone $order)->sum("amount"));
        //订单数量
        $data['order_num'] = (clone $order)->count();
        //非余额交易
        $data['online_amout'] = sprintf("%.2f", (clone $order)->where("pay_id", "!=", 1)->sum("amount"));
        //推广返利
        $data['divide_amount'] = sprintf("%.2f", (clone $order)->sum("divide_amount"));
        //分站盈利
        $data['rebate'] = sprintf("%.2f", (clone $order)->sum("rebate"));
        //供货商手续费
        $data['cost'] = sprintf("%.2f", (clone $order)->sum("cost"));
        //盈利
        $data['profit'] = (new Decimal($data['turnover']))->sub((clone $order)->sum("rent"))->sub($data['divide_amount'])->sub($data['rebate'])->add($data['cost'])->getAmount();

        //店铺数量
        $data['business'] = $business->count();
        //未处理的提现
        $data['cash_status_0'] = (clone $cash)->where("status", 0)->count();
        //总提现金额
        $data['cash_money_status_1'] = (clone $cash)->where("status", 1)->sum("amount");
        //充值金额
        $data['recharge_amount'] = (clone $recharge)->where("status", 1)->sum("amount");


        return $this->json(200, 'success', $data);
    }

    /**
     * 数据趋势：最近 7 天（滚动窗口，含今天）。
     * 不能按"自然周"取数：周一当天只有 1 个数据点，折线连不成线（前端又不显示孤点），
     * 图表必然全空，跨周的数据也永远看不到（issue #785）。
     * @return array
     */
    public function weekStatistics(): array
    {
        $dayLabels = [1 => "周一", 2 => "周二", 3 => "周三", 4 => "周四", 5 => "周五", 6 => "周六", 7 => "周日"];
        $begin = date("Y-m-d 00:00:00", strtotime("-6 day"));
        $end = date("Y-m-d 23:59:59");

        // 整个窗口按日 GROUP BY 一次取全，替代旧实现的逐日逐指标查询（最多 42 条 SQL → 3 条）
        $orderRows = \App\Model\Order::query()
            ->whereBetween("create_time", [$begin, $end])
            ->where("status", 1)
            ->selectRaw("DATE(create_time) as day, SUM(amount) as amount, SUM(divide_amount) as divide_amount, SUM(rebate) as rebate, SUM(cost) as cost, SUM(rent) as rent")
            ->groupBy("day")
            ->get()
            ->keyBy("day");
        $cashRows = \App\Model\Cash::query()
            ->whereBetween("create_time", [$begin, $end])
            ->where("status", 1)
            ->selectRaw("DATE(create_time) as day, SUM(amount) as amount")
            ->groupBy("day")
            ->get()
            ->keyBy("day");
        $rechargeRows = UserRecharge::query()
            ->whereBetween("create_time", [$begin, $end])
            ->where("status", 1)
            ->selectRaw("DATE(create_time) as day, SUM(amount) as amount")
            ->groupBy("day")
            ->get()
            ->keyBy("day");

        $weeks = [];
        $series = [
            "profit" => [],
            "trade" => [],
            "cash" => [],
            "recharge" => []
        ];

        for ($i = 6; $i >= 0; $i--) {
            $ts = strtotime("-{$i} day");
            $day = date("Y-m-d", $ts);
            $weeks[] = date("m-d", $ts) . " " . lang($dayLabels[(int)date("N", $ts)]);

            $order = $orderRows->get($day);
            $amount = (string)($order?->amount ?? "0");
            $series["trade"][] = sprintf("%.2f", $amount);
            $series["profit"][] = (new Decimal($amount))
                ->sub((string)($order?->rent ?? "0"))
                ->sub((string)($order?->divide_amount ?? "0"))
                ->sub((string)($order?->rebate ?? "0"))
                ->add((string)($order?->cost ?? "0"))
                ->getAmount();
            $series["cash"][] = sprintf("%.2f", (string)($cashRows->get($day)?->amount ?? "0"));
            $series["recharge"][] = sprintf("%.2f", (string)($rechargeRows->get($day)?->amount ?? "0"));
        }

        return $this->json(200, "success", [
            "series" => $series,
            "week" => $weeks
        ]);
    }
}