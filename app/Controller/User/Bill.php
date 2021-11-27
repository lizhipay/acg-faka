<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Controller\Base\View\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserSession::class])]
class Bill extends User
{
    /**
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    public function index(): string
    {
        return $this->theme("我的账单", "BILL", "User/Bill.html");
    }
}