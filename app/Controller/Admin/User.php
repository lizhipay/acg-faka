<?php
declare(strict_types=1);

namespace App\Controller\Admin;


use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use App\Model\UserRecharge;
use Kernel\Annotation\Interceptor;

#[Interceptor(ManageSession::class)]
class User extends Manage
{
    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function index(): string
    {

        $userCount = \App\Model\User::query()->count();
        $businessCount = \App\Model\User::query()->whereNotNull("business_level")->count();
        $balance = \App\Model\User::query()->sum("balance");
        $recharge = UserRecharge::query()->where("status", 1)->sum("amount");
        $coin = \App\Model\User::query()->sum("coin");
        $totalCoin = \App\Model\User::query()->sum("total_coin");

        return $this->render("会员管理", "User/User.html", [
            "userCount" => $userCount,
            "businessCount" => $businessCount,
            "balance" => $balance,
            "recharge" => $recharge,
            "coin" => $coin,
            "totalCoin" => $totalCoin
        ]);
    }


    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function group(): string
    {
        return $this->render("会员等级", "User/Group.html");
    }

    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function businessLevel(): string
    {
        return $this->render("商户等级", "User/BusinessLevel.html");
    }

    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function bill(): string
    {
        return $this->render("账单管理", "User/Bill.html");
    }
}