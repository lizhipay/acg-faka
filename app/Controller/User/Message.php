<?php
declare(strict_types=1);

namespace App\Controller\User;

use App\Controller\Base\View\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\ViewException;

#[Interceptor([Waf::class, UserSession::class])]
class Message extends User
{
    /**
     * @throws ViewException
     * @throws \ReflectionException
     */
    public function index(): string
    {
        return $this->theme('消息中心', 'MESSAGE', 'User/Message.html');
    }
}
