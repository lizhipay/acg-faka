<?php
declare(strict_types=1);

namespace App\Controller\User;

use App\Controller\Base\View\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
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
}