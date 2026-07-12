<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\ViewException;

#[Interceptor(ManageSession::class)]
class Ticket extends Manage
{
    /**
     * @throws ViewException
     */
    public function index(): string
    {
        return $this->render('工单管理', 'User/Ticket.html');
    }
}
