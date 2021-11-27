<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Controller\Base\View\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Util\Client;
use App\Util\Date;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserSession::class])]
class Dashboard extends User
{

    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function index(): string
    {

        $data = [];
        $model = \App\Model\Bill::query()->where("owner", $this->getUser()->id)->where("type", 1)->where("currency", 1);
        //今日收入
        $data['today_income'] = (clone $model)->whereBetween('create_time', [Date::calcDay(), Date::calcDay(1)])->sum("amount");
        //昨日收入
        $data['yesterday_income'] = (clone $model)->whereBetween('create_time', [Date::calcDay(-1), Date::calcDay()])->sum("amount");
        //本周
        $data['week_income'] = (clone $model)->whereBetween('create_time', [Date::weekDay(1, Date::TYPE_START), Date::weekDay(7, Date::TYPE_END)])->sum("amount");
        //总交易
        $data['trade'] = \App\Model\Order::query()->where("user_id", $this->getUser()->id)->where("status", 1)->sum("amount");

        //推广人数
        $data['children'] = \App\Model\User::query()->where("pid", $this->getUser()->id)->count();

        //推广链接
        $data['share_url'] = Client::getUrl() . "?from=" . $this->getUser()->id;

        return $this->theme("个人主页", "DASHBOARD", "Dashboard/Index.html", $data);
    }
}