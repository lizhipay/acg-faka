<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Controller\Base\View\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Util\Date;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserSession::class])]
class Dashboard extends User
{

    /**
     * 个人主页:所有人都有「资产 + 消费」;商家(有商户等级)额外有「经营」区。
     * (推广链接/推广人数已迁往 推广中心 /user/agent/promote)
     * @throws \Kernel\Exception\ViewException
     */
    public function index(): string
    {
        $user = $this->getUser();
        $uid = $user->id;
        $monthStart = date("Y-m-01 00:00:00");
        $data = [];

        //消费(所有人):本月购物 / 累计购物 / 订单数(「最近购买」列表已改由前端表格复用 /user/api/purchaseRecord/data)
        $buyModel = \App\Model\Order::query()->where("owner", $uid)->where("status", 1);
        $data['buy_month'] = sprintf("%.2f", (float)(clone $buyModel)->where("create_time", ">=", $monthStart)->sum("amount"));
        $data['buy_total'] = sprintf("%.2f", (float)(clone $buyModel)->sum("amount"));
        $data['buy_count'] = (clone $buyModel)->count();

        //经营(商家)
        if ($user->business_level) {
            $bill = \App\Model\Bill::query()->where("owner", $uid)->where("type", 1)->where("currency", 1);
            $data['today_income'] = sprintf("%.2f", (float)(clone $bill)->whereBetween('create_time', [Date::calcDay(), Date::calcDay(1)])->sum("amount"));
            $data['yesterday_income'] = sprintf("%.2f", (float)(clone $bill)->whereBetween('create_time', [Date::calcDay(-1), Date::calcDay()])->sum("amount"));
            $data['week_income'] = sprintf("%.2f", (float)(clone $bill)->whereBetween('create_time', [Date::weekDay(1, Date::TYPE_START), Date::weekDay(7, Date::TYPE_END)])->sum("amount"));
            $data['month_income'] = sprintf("%.2f", (float)(clone $bill)->where("create_time", ">=", $monthStart)->sum("amount"));

            $sellModel = \App\Model\Order::query()->where("user_id", $uid)->where("status", 1);
            $data['trade'] = sprintf("%.2f", (float)(clone $sellModel)->sum("amount"));
            $data['today_orders'] = (clone $sellModel)->whereBetween('create_time', [Date::calcDay(), Date::calcDay(1)])->count();
            $data['pending_delivery'] = (clone $sellModel)->where("delivery_status", 0)->count();
            $data['recent_sales'] = (clone $sellModel)->with(['commodity'])->orderBy("id", "desc")->limit(5)->get();

            $data['card_unsold'] = \App\Model\Card::query()->where("owner", $uid)->where("status", 0)->count();
            $data['commodity_online'] = \App\Model\Commodity::query()->where("owner", $uid)->where("status", 1)->count();
            $data['commodity_count'] = \App\Model\Commodity::query()->where("owner", $uid)->count();

            //近 7 日硬币收入走势(含今天)
            $series = [];
            $max = 0;
            for ($i = 6; $i >= 0; $i--) {
                $amount = (float)(clone $bill)->whereBetween('create_time', [Date::calcDay(-$i), Date::calcDay(-$i + 1)])->sum("amount");
                $max = max($max, $amount);
                $series[] = [
                    'label' => date("m-d", strtotime(Date::calcDay(-$i))),
                    'amount' => sprintf("%.2f", $amount),
                    'value' => $amount,
                ];
            }
            foreach ($series as &$item) {
                $item['pct'] = $max > 0 ? max(3, (int)round($item['value'] / $max * 100)) : 3;
            }
            $data['week_series'] = $series;
            $data['week_series_max'] = sprintf("%.2f", $max);
        }

        return $this->theme("个人主页", "DASHBOARD", "Dashboard/Index.html", $data);
    }
}
