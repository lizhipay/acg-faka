<?php
declare(strict_types=1);

namespace App\Controller\Admin;


use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;

#[Interceptor(ManageSession::class)]
class Cash extends Manage
{
    /**
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    public function index(): string
    {
        return $this->render("提现管理", "User/Cash.html");
    }
}