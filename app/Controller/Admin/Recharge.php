<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;

#[Interceptor(ManageSession::class)]
class Recharge extends Manage
{
    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function order(): string
    {
        $userId = $_GET['userId'];
        return $this->render("充值订单", "User/Order.html", ["userId" => $userId]);
    }
}