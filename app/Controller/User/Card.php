<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Controller\Base\View\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use Kernel\Annotation\Interceptor;
use App\Interceptor\Business;

#[Interceptor([Waf::class, UserSession::class, Business::class])]
class Card extends User
{
    /**
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    public function index(): string
    {
        return $this->theme("卡密管理", "CARD", "User/Card.html");
    }
}