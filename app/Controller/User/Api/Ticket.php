<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Ticket as TicketService;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Waf\Filter;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class Ticket extends User
{
    #[Inject]
    private TicketService $ticket;

    public function data(): array
    {
        $filter = (array)$this->request->post(flags: Filter::NORMAL);
        return $this->json(data: $this->ticket->userData($this->getUser(), $filter));
    }

    public function detail(): array
    {
        $id = (int)$this->request->post('id', Filter::INTEGER);
        $limit = (int)($this->request->post('limit', Filter::INTEGER) ?: 30);
        return $this->json(data: $this->ticket->userDetail($this->getUser(), $id, $limit));
    }

    public function messages(): array
    {
        $id = (int)$this->request->post('id', Filter::INTEGER);
        $afterId = (int)$this->request->post('after_id', Filter::INTEGER);
        $beforeId = (int)$this->request->post('before_id', Filter::INTEGER);
        $limit = (int)($this->request->post('limit', Filter::INTEGER) ?: 50);
        return $this->json(data: $this->ticket->userMessages($this->getUser(), $id, $afterId, $beforeId, $limit));
    }

    public function commodityOptions(): array
    {
        $filter = array_merge(
            (array)$this->request->get(flags: Filter::NORMAL),
            (array)$this->request->post(flags: Filter::NORMAL)
        );
        return $this->json(data: $this->ticket->commodityOptions($this->getUser(), $filter));
    }

    public function orderOptions(): array
    {
        $filter = array_merge(
            (array)$this->request->get(flags: Filter::NORMAL),
            (array)$this->request->post(flags: Filter::NORMAL)
        );
        return $this->json(data: $this->ticket->orderOptions($this->getUser(), $filter));
    }

    public function create(): array
    {
        $map = (array)$this->request->post(flags: Filter::NORMAL);
        return $this->json(200, '工单创建成功', $this->ticket->create($this->getUser(), $map));
    }

    public function reply(): array
    {
        $id = (int)$this->request->post('id', Filter::INTEGER);
        $content = (string)$this->request->post('content', Filter::NORMAL);
        return $this->json(200, '回复成功', $this->ticket->userReply($this->getUser(), $id, $content));
    }

    public function upload(): array
    {
        return $this->json(200, '上传成功', $this->ticket->upload(
            $this->getUser(),
            null,
            (array)$this->request->file('file')
        ));
    }

    public function badge(): array
    {
        return $this->json(data: $this->ticket->userBadge($this->getUser()));
    }
}
