<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use App\Service\Message as MessageService;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\ViewException;

#[Interceptor(ManageSession::class)]
class Message extends Manage
{
    #[Inject]
    private MessageService $message;

    /**
     * @throws ViewException
     */
    public function index(): string
    {
        return $this->render('消息管理', 'User/Message.html', [
            'email_enabled' => $this->message->emailAvailable(),
        ]);
    }
}
