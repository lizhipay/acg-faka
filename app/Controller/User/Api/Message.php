<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Message as MessageService;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Waf\Filter;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class Message extends User
{
    #[Inject]
    private MessageService $message;

    public function recent(): array
    {
        return $this->json(data: $this->message->recent($this->getUser()));
    }

    public function data(): array
    {
        return $this->json(data: $this->message->userData(
            $this->getUser(),
            (array)$this->request->post(flags: Filter::NORMAL)
        ));
    }

    public function detail(): array
    {
        $id = (int)$this->request->post('id', Filter::INTEGER);
        return $this->json(data: $this->message->userDetail($this->getUser(), $id));
    }

    public function del(): array
    {
        return $this->json(200, '消息删除成功', $this->message->userDelete(
            $this->getUser(),
            $this->ids()
        ));
    }

    public function clear(): array
    {
        return $this->json(200, '消息已清空', $this->message->userClear($this->getUser()));
    }

    private function ids(): array
    {
        $values = $this->request->post('list', Filter::NORMAL);
        if (!is_array($values)) {
            $values = $this->request->post('ids', Filter::NORMAL);
        }
        if (!is_array($values)) {
            $values = [(int)$this->request->post('id', Filter::INTEGER)];
        }
        return array_values(array_unique(array_filter(array_map('intval', $values), static fn(int $id) => $id > 0)));
    }
}
