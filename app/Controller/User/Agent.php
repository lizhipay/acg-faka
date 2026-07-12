<?php
declare(strict_types=1);

namespace App\Controller\User;

use App\Controller\Base\View\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Model\UserGroup;
use App\Util\Client;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserSession::class])]
class Agent extends User
{
    /**
     * @throws \ReflectionException
     * @throws \Kernel\Exception\ViewException
     */
    public function member(): string
    {
        return $this->theme("我的下级", "AGENT_MEMBER", "Agent/Member.html");
    }

    /**
     * 推广中心:推广链接/人数 + 分成收益统计 + 商品预计收益表(表格走 /user/api/promote/data)
     * 收益口径与下单结算一致:成交价 - 按我的会员等级计算的拿货价 = 我的分成(硬币入账)
     * @throws \ReflectionException
     * @throws \Kernel\Exception\ViewException
     */
    public function promote(): string
    {
        $user = $this->getUser();
        $monthStart = date("Y-m-01 00:00:00");
        $data = [];

        $data['share_url'] = Client::getUrl() . "?from=" . $user->id;
        $data['children'] = \App\Model\User::query()->where("pid", $user->id)->count();

        $orders = \App\Model\Order::query()->where("from", $user->id)->where("status", 1);
        $data['promote_orders'] = (clone $orders)->count();
        $data['promote_total'] = sprintf("%.2f", (float)(clone $orders)->sum("divide_amount"));
        $data['promote_month'] = sprintf("%.2f", (float)(clone $orders)->where("create_time", ">=", $monthStart)->sum("divide_amount"));
        $data['recent'] = (clone $orders)->with(['commodity'])->orderBy("id", "desc")->limit(8)->get();

        $data['group'] = UserGroup::get((float)$user->recharge);

        return $this->theme("推广中心", "AGENT_PROMOTE", "Agent/Promote.html", $data);
    }
}
