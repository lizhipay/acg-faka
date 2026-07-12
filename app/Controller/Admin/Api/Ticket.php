<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Service\Ticket as TicketService;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Waf\Filter;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Ticket extends Manage
{
    #[Inject]
    private TicketService $ticket;

    public function data(): array
    {
        $filter = (array)$this->request->post(flags: Filter::NORMAL);
        return $this->json(data: $this->ticket->adminData($filter));
    }

    public function detail(): array
    {
        $id = (int)$this->request->post('id', Filter::INTEGER);
        $limit = (int)($this->request->post('limit', Filter::INTEGER) ?: 30);
        return $this->json(data: $this->ticket->adminDetail($this->getManage(), $id, $limit));
    }

    public function messages(): array
    {
        $id = (int)$this->request->post('id', Filter::INTEGER);
        $afterId = (int)$this->request->post('after_id', Filter::INTEGER);
        $beforeId = (int)$this->request->post('before_id', Filter::INTEGER);
        $limit = (int)($this->request->post('limit', Filter::INTEGER) ?: 50);
        return $this->json(data: $this->ticket->adminMessages($this->getManage(), $id, $afterId, $beforeId, $limit));
    }

    public function reply(): array
    {
        $id = (int)$this->request->post('id', Filter::INTEGER);
        $content = (string)$this->request->post('content', Filter::NORMAL);
        $mode = (string)($this->request->post('mode', Filter::NORMAL) ?: 'reply');
        return $this->json(200, $mode === 'resolve' ? '回复并解决成功' : '回复成功', $this->ticket->adminReply(
            $this->getManage(),
            $id,
            $content,
            $mode
        ));
    }

    public function close(): array
    {
        $id = (int)$this->request->post('id', Filter::INTEGER);
        return $this->json(200, '工单已关闭', $this->ticket->close($this->getManage(), $id));
    }

    public function upload(): array
    {
        return $this->json(200, '上传成功', $this->ticket->upload(
            null,
            $this->getManage(),
            (array)$this->request->file('file')
        ));
    }

    public function badge(): array
    {
        return $this->json(data: $this->ticket->adminBadge());
    }
}
