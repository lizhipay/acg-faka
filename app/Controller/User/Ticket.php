<?php
declare(strict_types=1);

namespace App\Controller\User;

use App\Controller\Base\View\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\ViewException;

#[Interceptor([Waf::class, UserSession::class])]
class Ticket extends User
{
    /**
     * @throws ViewException
     * @throws \ReflectionException
     */
    public function index(): string
    {
        return $this->theme('我的工单', 'TICKET', 'User/Ticket.html');
    }

    /**
     * @throws ViewException
     * @throws \ReflectionException
     */
    public function create(): string
    {
        return $this->theme('创建工单', 'TICKET_CREATE', 'User/TicketCreate.html');
    }

    /**
     * @throws ViewException
     * @throws \ReflectionException
     */
    public function detail(): string
    {
        return $this->theme('工单详情', 'TICKET_DETAIL', 'User/TicketDetail.html', [
            'ticketId' => max(0, (int)($_GET['id'] ?? 0)),
        ]);
    }
}
