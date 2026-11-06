<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Model\Business;
use App\Model\Cash;
use App\Model\Commodity;
use App\Model\Order;
use App\Model\Pay;
use App\Model\Ticket;
use App\Model\User;
use App\Model\UserRecharge;
use App\Util\Currency;
use App\Util\Date;
use Kernel\Annotation\Interceptor;
use Kernel\Util\Decimal;

#[Interceptor(\App\Interceptor\ManageSession::class, Interceptor::TYPE_API)]
class Dashboard extends \App\Controller\Base\API\Manage
{
    /**
     * 单笔已支付订单里「站长净赚」的 SQL 口径，与 Order::trade / orderSuccess 的资金流向一一对应：
     *   amount        顾客实付（已含支付通道手续费）
     *   pay_cost      支付通道手续费：随付款交给支付通道，不是站长的钱（旧单可能为 NULL）
     *   rent          进货成本：对接商品=上游进价；自营商品=后台填的进货价；商户商品恒为 0（商户不能设进货价）
     *   rebate        分成：商户自己的商品(user_id>0)付给商户；平台商品在分站卖出付给分站主（可能为 NULL）
     *   divide_amount 推广佣金：付给推广人（可能为 NULL）
     * 净赚 = amount − pay_cost − rent − rebate − divide_amount
     *
     * 可空列必须逐项 COALESCE：任一项为 NULL 会让整行表达式变 NULL，被 SUM 静默跳过而少算（同 #903）。
     * 旧实现用的 cost 列是历史死列（全仓无赋值、恒为 0），而且没扣支付通道手续费，导致「盈利」虚高、「供货手续费」恒为 0。
     */
    private const NET_SQL = 'amount - COALESCE(pay_cost,0) - rent - COALESCE(rebate,0) - COALESCE(divide_amount,0)';

    /** pay 表 id=1 是系统内置的「余额」支付 */
    private const PAY_BALANCE = 1;

    /**
     * cash.card=2 是「兑现到钱包余额」：钱没有离开站点；其余收款方式（支付宝/微信/USDT）是真实打款。
     * 注意是 card（收款方式）不是 type（0=自动结算 1=手动提交）。
     */
    private const CASH_TO_BALANCE = 2;

    /** 趋势可选的天数（滚动窗口，含今天），第一个是默认值 */
    private const TREND_DAYS = [7, 30];

    /**
     * 统计窗口（闭区间，终点一律当天/当月 23:59:59，见 stat-time-window-boundaries）。
     * @return array{0:string,1:string}|null null=全部时间
     */
    private function window(int $type): ?array
    {
        return match ($type) {
            1 => [Date::calcDay(-1), Date::calcDay(-1, Date::TYPE_END)],
            2 => [Date::weekDay(1, Date::TYPE_START), Date::weekDay(7, Date::TYPE_END)],
            3 => [Date::monthDay(), Date::monthDay(Date::TYPE_END)],
            4 => null,
            default => [Date::calcDay(), Date::calcDay(0, Date::TYPE_END)],
        };
    }

    private static function money(mixed $value): string
    {
        return (new Decimal((string)($value ?? '0'), 2))->getAmount();
    }

    /**
     * 已支付订单在一个窗口内的全部金额口径，一条 SQL 取齐。
     * 已退款到余额的订单（#1145）不算成交：本页所有订单统计都加 unrefunded()。
     */
    private function orderStats(?array $time): object
    {
        $query = Order::query()->where('status', 1)->unrefunded();
        $time && $query->whereBetween('create_time', $time);
        $net = self::NET_SQL;

        return $query->selectRaw("
            COUNT(*) AS order_num,
            COALESCE(SUM(amount),0) AS turnover,
            COALESCE(SUM(COALESCE(pay_cost,0)),0) AS pay_cost,
            COALESCE(SUM(rent),0) AS rent,
            COALESCE(SUM(COALESCE(rebate,0)),0) AS rebate,
            COALESCE(SUM(CASE WHEN user_id > 0 THEN COALESCE(rebate,0) ELSE 0 END),0) AS rebate_merchant,
            COALESCE(SUM(CASE WHEN user_id = 0 THEN COALESCE(rebate,0) ELSE 0 END),0) AS rebate_substation,
            COALESCE(SUM(COALESCE(divide_amount,0)),0) AS divide_amount,
            COALESCE(SUM({$net}),0) AS profit,
            COALESCE(SUM(CASE WHEN user_id > 0 THEN {$net} ELSE 0 END),0) AS merchant_commission,
            COALESCE(SUM(CASE WHEN pay_id <> ? THEN amount ELSE 0 END),0) AS online_amount,
            COALESCE(SUM(CASE WHEN pay_id = ? THEN amount ELSE 0 END),0) AS balance_amount,
            COUNT(DISTINCT CASE WHEN owner > 0 THEN owner END) AS member_buyers,
            COUNT(DISTINCT CASE WHEN owner = 0 THEN contact END) AS guest_buyers
        ", [self::PAY_BALANCE, self::PAY_BALANCE])->toBase()->first();
    }

    /**
     * 各支付通道在窗口内收到的钱：商品订单 + 会员充值。两者走的是同一个网关，对账时必须合在一起看。
     *   amount          已含通道手续费，就是顾客在网关里实际付的数（站点货币）
     *   gateway_amount  下单时提交给网关的 CNY 快照；升级前的旧单没有快照，那时站点只有人民币，直接用 amount
     * 时间口径与页面其它数字一致（按下单时间），订单金额列相加 = 成交额，充值金额列相加 = 会员充值；
     * 所以订单同样不含已退款的（#1145）。
     * 通道被删掉后 name 为 null，由前端显示编号；余额（pay_id=1）不是外部通道，排在最后。
     * @return array<int, array<string, mixed>>
     */
    private function channelStats(?array $time): array
    {
        $collect = function ($query) use ($time) {
            $query->where('status', 1);
            $time && $query->whereBetween('create_time', $time);
            return $query
                ->selectRaw('pay_id, COUNT(*) AS num, COALESCE(SUM(amount),0) AS amount, COALESCE(SUM(COALESCE(gateway_amount, amount)),0) AS gateway')
                ->groupBy('pay_id')
                ->toBase()
                ->get()
                ->keyBy(fn($row) => (int)$row->pay_id);
        };
        $orders = $collect(Order::query()->unrefunded());
        $recharges = $collect(UserRecharge::query());

        $ids = array_values(array_unique(array_merge($orders->keys()->all(), $recharges->keys()->all())));
        if (!$ids) {
            return [];
        }
        $pays = Pay::query()->whereIn('id', $ids)->get(['id', 'name', 'icon'])->keyBy('id');

        $rows = [];
        foreach ($ids as $id) {
            $order = $orders->get($id);
            $recharge = $recharges->get($id);
            $pay = $pays->get($id);
            $orderAmount = self::money($order?->amount ?? 0);
            $rechargeAmount = self::money($recharge?->amount ?? 0);
            $rows[] = [
                'pay_id' => $id,
                'name' => $pay?->name,
                'icon' => $pay?->icon ?: null,
                'is_balance' => $id === self::PAY_BALANCE,
                'order_num' => (int)($order?->num ?? 0),
                'order_amount' => $orderAmount,
                'recharge_num' => (int)($recharge?->num ?? 0),
                'recharge_amount' => $rechargeAmount,
                'total' => (new Decimal($orderAmount))->add($rechargeAmount)->getAmount(),
                'gateway' => (new Decimal(self::money($order?->gateway ?? 0)))->add(self::money($recharge?->gateway ?? 0))->getAmount(),
            ];
        }

        usort($rows, function (array $a, array $b): int {
            if ($a['is_balance'] !== $b['is_balance']) {
                return $a['is_balance'] ? 1 : -1;
            }
            return bccomp($b['total'], $a['total'], 2) ?: $a['pay_id'] <=> $b['pay_id'];
        });
        return $rows;
    }

    /**
     * 数据概览：所选时间段内的经营明细。
     * 旧字段名（turnover/profit/rebate/cost…）保持不变，手机端 recipes 仍在读；数值口径已修正。
     * @param int $type 0=今天 1=昨天 2=本周 3=本月 4=全部
     * @return array
     */
    public function data(int $type): array
    {
        $time = $this->window($type);
        $order = $this->orderStats($time);

        $cashQuery = Cash::query();
        $time && $cashQuery->whereBetween('create_time', $time);
        $cash = $cashQuery->selectRaw('
            COUNT(CASE WHEN status = 0 THEN 1 END) AS pending_num,
            COALESCE(SUM(CASE WHEN status = 1 THEN amount ELSE 0 END),0) AS done_amount,
            COALESCE(SUM(CASE WHEN status = 1 AND card <> ? THEN amount ELSE 0 END),0) AS paid_out,
            COALESCE(SUM(CASE WHEN status = 1 AND card = ? THEN amount ELSE 0 END),0) AS to_balance,
            COALESCE(SUM(CASE WHEN status = 1 THEN cost ELSE 0 END),0) AS fee_income
        ', [self::CASH_TO_BALANCE, self::CASH_TO_BALANCE])->toBase()->first();

        $rechargeQuery = UserRecharge::query()->where('status', 1);
        $time && $rechargeQuery->whereBetween('create_time', $time);
        $recharge = $rechargeQuery->selectRaw('COUNT(*) AS num, COALESCE(SUM(amount),0) AS amount')->toBase()->first();

        $userQuery = User::query();
        $businessQuery = Business::query();
        $unpaidQuery = Order::query()->where('status', 0);
        if ($time) {
            $userQuery->whereBetween('create_time', $time);
            $businessQuery->whereBetween('create_time', $time);
            $unpaidQuery->whereBetween('create_time', $time);
        }

        $orderNum = (int)$order->order_num;
        $turnover = self::money($order->turnover);

        return $this->json(200, 'success', [
            //—— 旧字段（口径已修正）——
            'turnover' => $turnover,
            'order_num' => $orderNum,
            'online_amout' => self::money($order->online_amount),
            'divide_amount' => self::money($order->divide_amount),
            'rebate' => self::money($order->rebate),
            //「供货手续费」= 平台从商户商品里抽的成（旧实现读死列，恒为 0）
            'cost' => self::money($order->merchant_commission),
            'profit' => self::money($order->profit),
            'business' => $businessQuery->count(),
            'cash_status_0' => (int)$cash->pending_num,
            'cash_money_status_1' => self::money($cash->done_amount),
            'recharge_amount' => self::money($recharge->amount),
            'user_register_num' => $userQuery->count(),
            //—— 新增明细 ——
            'pay_cost' => self::money($order->pay_cost),
            'rent' => self::money($order->rent),
            'rebate_merchant' => self::money($order->rebate_merchant),
            'rebate_substation' => self::money($order->rebate_substation),
            'balance_amount' => self::money($order->balance_amount),
            'buyer_num' => (int)$order->member_buyers + (int)$order->guest_buyers,
            'avg_order' => $orderNum > 0 ? (new Decimal($turnover, 4))->div($orderNum)->getAmount() : '0.00',
            'unpaid_order_num' => $unpaidQuery->count(),
            'cash_paid_out' => self::money($cash->paid_out),
            'cash_to_balance' => self::money($cash->to_balance),
            'cash_fee_income' => self::money($cash->fee_income),
            'recharge_num' => (int)$recharge->num,
            //—— 支付通道对账 ——
            'channels' => $this->channelStats($time),
            //站点货币不是人民币时，网关里记的是换算后的 CNY，前端据此多显示一行
            'gateway_cny' => !Currency::isBypass(),
            //统计区间原样给前端显示（本周从周几开始、本月到哪天，以服务器时区为准）；全部时间为 null
            'range' => $time ? ['start' => $time[0], 'end' => $time[1]] : null,
        ]);
    }

    /**
     * 赚钱看板：今天 / 昨天 / 本月，各自带「公平」的对比基准，外加上个月全月，
     * 以及不随时间筛选变化的「待你处理」事项。
     * 所有窗口一条条件聚合 SQL 取齐，扫描区间=上月 1 号～今天，走 create_time 索引。
     *
     * 对比基准必须「截到同一时刻」：今天还没过完，拿它跟昨天一整天比，上午永远显示「少赚」，会误导人。
     * 所以今天对比「昨天到这个时刻」，本月对比「上月到同一天的这个时刻」；昨天是完整一天，对比完整的前天。
     * @return array
     */
    public function overview(): array
    {
        $today = [Date::calcDay(), Date::calcDay(0, Date::TYPE_END)];
        $now = date('H:i:s');
        //用「本月 1 号 −1 month」求上月：直接对今天减一个月，在 3/31 这类日子会溢出到 3/3
        $lastMonthFirst = strtotime(date('Y-m-01 00:00:00') . ' -1 month');
        $dayOfMonth = (int)date('j');
        //上月没有「今天这一天」（如 3/31 对应 2 月）时，截到上月最后一天结束
        $lastMonthSameEnd = $dayOfMonth <= (int)date('t', $lastMonthFirst)
            ? date('Y-m-', $lastMonthFirst) . sprintf('%02d', $dayOfMonth) . ' ' . $now
            : date('Y-m-t 23:59:59', $lastMonthFirst);

        $periods = [
            'today' => $today,
            'yesterday' => [Date::calcDay(-1), Date::calcDay(-1, Date::TYPE_END)],
            'yesterday_until_now' => [Date::calcDay(-1), date('Y-m-d', strtotime('-1 day')) . ' ' . $now],
            'day_before' => [Date::calcDay(-2), Date::calcDay(-2, Date::TYPE_END)],
            'month' => [Date::monthDay(), Date::monthDay(Date::TYPE_END)],
            'last_month_same' => [date('Y-m-01 00:00:00', $lastMonthFirst), $lastMonthSameEnd],
            'last_month' => [date('Y-m-01 00:00:00', $lastMonthFirst), date('Y-m-t 23:59:59', $lastMonthFirst)],
        ];

        $net = self::NET_SQL;
        $selects = [];
        $bindings = [];
        foreach ($periods as $key => [$start, $end]) {
            $selects[] = "COALESCE(SUM(CASE WHEN create_time BETWEEN ? AND ? THEN {$net} ELSE 0 END),0) AS {$key}_profit";
            $selects[] = "COALESCE(SUM(CASE WHEN create_time BETWEEN ? AND ? THEN amount ELSE 0 END),0) AS {$key}_turnover";
            $selects[] = "COUNT(CASE WHEN create_time BETWEEN ? AND ? THEN 1 END) AS {$key}_orders";
            array_push($bindings, $start, $end, $start, $end, $start, $end);
        }

        $row = Order::query()
            ->where('status', 1)
            ->unrefunded()
            ->whereBetween('create_time', [$periods['last_month'][0], $today[1]])
            ->selectRaw(implode(', ', $selects), $bindings)
            ->toBase()
            ->first();

        $stats = [];
        foreach ($periods as $key => [$start, $end]) {
            $stats[$key] = [
                'profit' => self::money($row->{"{$key}_profit"} ?? 0),
                'turnover' => self::money($row->{"{$key}_turnover"} ?? 0),
                'orders' => (int)($row->{"{$key}_orders"} ?? 0),
                'start' => $start,
                'end' => $end,
            ];
        }

        //待你处理：跟时间筛选无关——昨天提交、今天还没处理的提现，今天也必须看得见。
        //每项单独兜底：老站点升级后可能缺某张表（如工单），一项失败不能让整块看板空掉。
        $todo = ['cash_num' => 0, 'cash_amount' => '0.00', 'delivery_num' => 0, 'ticket_num' => 0];
        try {
            $pendingCash = Cash::query()->where('status', 0)
                ->selectRaw('COUNT(*) AS num, COALESCE(SUM(amount),0) AS amount')->toBase()->first();
            $todo['cash_num'] = (int)$pendingCash->num;
            $todo['cash_amount'] = self::money($pendingCash->amount);
        } catch (\Throwable) {
        }
        try {
            //等你发货：平台自己的商品（user_id=0）里，手动发货类、顾客已付款但还没发货的订单。商户商品由商户自己发货，不算站长的待办
            //已退款的单不用再发货
            $todo['delivery_num'] = Order::query()
                ->where('status', 1)
                ->unrefunded()
                ->where('delivery_status', 0)
                ->where('user_id', 0)
                ->whereIn('commodity_id', Commodity::query()->where('delivery_way', 1)->select('id'))
                ->count();
        } catch (\Throwable) {
        }
        try {
            $todo['ticket_num'] = Ticket::query()->where('status', 0)->count();
        } catch (\Throwable) {
        }

        $manage = $this->getManage();

        return $this->json(200, 'success', [
            'stats' => $stats,
            'todo' => $todo,
            //提现/充值明细页只对站长开放（F-12），前端据此决定待办是否可点击跳转
            'is_owner' => $manage && (int)$manage->type === 0,
        ]);
    }

    /**
     * 最近 N 天（滚动窗口，含今天）逐日汇总；没有数据的日子补 0，保证 N 个点连续。
     * 不能按「自然周」取数：周一当天只有 1 个点，跨周的数据也永远看不到（issue #785）。
     * 整个窗口按日 GROUP BY 一次取全（3 条 SQL），不逐日查询。
     * @return array<string, array{profit:string, turnover:string, orders:int, recharge:string, cash:string}> 键为 Y-m-d，按日期升序
     */
    private function daily(int $days): array
    {
        $now = time();
        $range = [date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' day', $now)), date('Y-m-d 23:59:59', $now)];

        $orderRows = Order::query()
            ->whereBetween('create_time', $range)
            ->where('status', 1)
            ->unrefunded()
            ->selectRaw('DATE(create_time) AS day, COUNT(*) AS orders, COALESCE(SUM(amount),0) AS turnover, COALESCE(SUM(' . self::NET_SQL . '),0) AS profit')
            ->groupBy('day')
            ->toBase()
            ->get()
            ->keyBy('day');
        //提现只算真正打出去的钱，与经营数据口径一致（兑现到钱包余额不算）
        $cashRows = Cash::query()
            ->whereBetween('create_time', $range)
            ->where('status', 1)
            ->where('card', '<>', self::CASH_TO_BALANCE)
            ->selectRaw('DATE(create_time) AS day, COALESCE(SUM(amount),0) AS amount')
            ->groupBy('day')
            ->toBase()
            ->get()
            ->keyBy('day');
        $rechargeRows = UserRecharge::query()
            ->whereBetween('create_time', $range)
            ->where('status', 1)
            ->selectRaw('DATE(create_time) AS day, COALESCE(SUM(amount),0) AS amount')
            ->groupBy('day')
            ->toBase()
            ->get()
            ->keyBy('day');

        $rows = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} day", $now));
            $order = $orderRows->get($day);
            $rows[$day] = [
                'profit' => self::money($order?->profit ?? 0),
                'turnover' => self::money($order?->turnover ?? 0),
                'orders' => (int)($order?->orders ?? 0),
                'recharge' => self::money($rechargeRows->get($day)?->amount ?? 0),
                'cash' => self::money($cashRows->get($day)?->amount ?? 0),
            ];
        }
        return $rows;
    }

    /**
     * 趋势：最近 7 / 30 天逐日的利润、成交额、订单数、会员充值，以及区间合计。
     * @param int $days 7 或 30，其它值按 7
     * @return array
     */
    public function trend(int $days): array
    {
        $days = in_array($days, self::TREND_DAYS, true) ? $days : self::TREND_DAYS[0];
        $rows = $this->daily($days);

        $sum = ['profit' => new Decimal('0'), 'turnover' => new Decimal('0'), 'recharge' => new Decimal('0')];
        $orders = 0;
        $list = [];
        foreach ($rows as $day => $row) {
            foreach ($sum as $key => $decimal) {
                $sum[$key] = $decimal->add($row[$key]);
            }
            $orders += $row['orders'];
            $list[] = [
                'date' => $day,
                'profit' => $row['profit'],
                'turnover' => $row['turnover'],
                'orders' => $row['orders'],
                'recharge' => $row['recharge'],
            ];
        }

        return $this->json(200, 'success', [
            'days' => $list,
            'total' => [
                'profit' => $sum['profit']->getAmount(),
                'turnover' => $sum['turnover']->getAmount(),
                'orders' => $orders,
                'recharge' => $sum['recharge']->getAmount(),
            ],
        ]);
    }

    /**
     * 旧版趋势接口（最近 7 天），返回结构保持不变，供仍在调用它的地方使用；控制台已改用 trend()。
     * @return array
     */
    public function weekStatistics(): array
    {
        $dayLabels = [1 => "周一", 2 => "周二", 3 => "周三", 4 => "周四", 5 => "周五", 6 => "周六", 7 => "周日"];
        $weeks = [];
        $series = ["profit" => [], "trade" => [], "cash" => [], "recharge" => []];

        foreach ($this->daily(7) as $day => $row) {
            $ts = strtotime($day);
            $weeks[] = date("m-d", $ts) . " " . lang($dayLabels[(int)date("N", $ts)]);
            $series["profit"][] = $row['profit'];
            $series["trade"][] = $row['turnover'];
            $series["cash"][] = $row['cash'];
            $series["recharge"][] = $row['recharge'];
        }

        return $this->json(200, "success", [
            "series" => $series,
            "week" => $weeks
        ]);
    }
}
