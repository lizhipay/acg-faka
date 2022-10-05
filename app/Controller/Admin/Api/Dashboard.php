<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Model\Business;
use App\Model\UserRecharge;
use App\Util\Date;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\Post;

#[Interceptor(\App\Interceptor\ManageSession::class, Interceptor::TYPE_API)]
class Dashboard extends \App\Controller\Base\API\Manage
{
    /**
     * @param int $type
     * @return array
     */
    public function data(#[Post] int $type): array
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
            $data['user_login_num'] = (clone $user)->count();

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
            $data['user_login_num'] = (clone $user)->whereBetween("login_time", $time)->count();
        }

        //全站营业额
        $data['turnover'] = sprintf("%.2f", (clone $order)->sum("amount"));
        //订单数量
        $data['order_num'] = (clone $order)->count();
        //手续费
        $data['cost'] = sprintf("%.2f", (clone $order)->where("user_id", "!=", 0)->sum("cost"));
        //非余额交易
        $data['online_amout'] = sprintf("%.2f", (clone $order)->where("pay_id", "!=", 1)->sum("amount"));
        //返利
        $data['rebate'] = sprintf("%.2f", (clone $order)->sum("rebate"));
        //店铺数量
        $data['business'] = $business->count();
        //未处理的提现
        $data['cash_status_0'] = (clone $cash)->where("status", 0)->count();
        //总提现金额
        $data['cash_money_status_1'] = (clone $cash)->where("status", 1)->sum("amount");
        //充值金额
        $data['recharge_amount'] = (clone $recharge)->where("status", 1)->sum("amount");


        //盈利
        $data['rent'] = (
                (clone $order)->where("user_id", 0)->sum("amount") -
                (clone $order)->where("user_id", 0)->sum("premium") -
                (clone $order)->where("user_id", 0)->sum("rent"))
            + (float)$data['cost'];
        $data['rent'] = sprintf("%.2f", $data['rent']);;

        return $this->json(200, 'success', $data);
    }

    /**
     * 本周数据
     * @return array
     */
    public function weekStatistics(): array
    {
        $w = date('w');
        $w = $w == 0 ? 7 : $w;

        $week = [
            1 => "星期一",
            2 => "星期二",
            3 => "星期三",
            4 => "星期四",
            5 => "星期五",
            6 => "星期六",
            7 => "星期七"
        ];

        $weeks = [];


        $series = [
            "trade" => [],
            "profit" => [],
            "cost" => [],
            "cash" => [],
            "recharge" => [],
        ];

        for ($i = 1; $i <= $w; $i++) {
            $weeks[] = $week[$i];
            //交易额
            $amount = \App\Model\Order::query()->whereBetween("create_time", [Date::weekDay($i, Date::TYPE_START), Date::weekDay($i, Date::TYPE_END)])->where("status", 1)->sum("amount");
            $series["trade"][] = sprintf("%.2f", $amount);
            //手续费
            $cost = \App\Model\Order::query()->whereBetween("create_time", [Date::weekDay($i, Date::TYPE_START), Date::weekDay($i, Date::TYPE_END)])->where("status", 1)->where("user_id", "!=", 0)->sum("cost");
            $series["cost"][] = sprintf("%.2f", $cost);
            //纯盈利
            $rent = \App\Model\Order::query()->whereBetween("create_time", [Date::weekDay($i, Date::TYPE_START), Date::weekDay($i, Date::TYPE_END)])->where("status", 1)->where("user_id", 0)->sum("rent");//主站成本
            $premium = \App\Model\Order::query()->whereBetween("create_time", [Date::weekDay($i, Date::TYPE_START), Date::weekDay($i, Date::TYPE_END)])->where("status", 1)->where("user_id", 0)->sum("premium");//分站加价

            $profit = (\App\Model\Order::query()->whereBetween("create_time", [Date::weekDay($i, Date::TYPE_START), Date::weekDay($i, Date::TYPE_END)])->where("status", 1)->where("user_id", 0)->sum("amount") - $premium - $rent) + $cost;;
            $series["profit"][] = sprintf("%.2f", $profit);
            //提现
            $cash = \App\Model\Cash::query()->whereBetween("create_time", [Date::weekDay($i, Date::TYPE_START), Date::weekDay($i, Date::TYPE_END)])->where("status", 1)->sum("amount");
            $series["cash"][] = sprintf("%.2f", $cash);
            //充值
            $recharge = \App\Model\UserRecharge::query()->whereBetween("create_time", [Date::weekDay($i, Date::TYPE_START), Date::weekDay($i, Date::TYPE_END)])->where("status", 1)->sum("amount");;
            $series["recharge"][] = sprintf("%.2f", $recharge);
        }

        return $this->json(200, "success", [
            "series" => $series,
            "week" => $weeks
        ]);
    }
}