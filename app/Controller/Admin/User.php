<?php
declare(strict_types=1);

namespace App\Controller\Admin;


use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;

#[Interceptor(ManageSession::class)]
class User extends Manage
{
    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function index(): string
    {
        return $this->render("会员管理", "User/User.html");
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