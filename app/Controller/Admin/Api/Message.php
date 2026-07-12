<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Service\Message as MessageService;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Waf\Filter;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Message extends Manage
{
    #[Inject]
    private MessageService $message;

    public function data(): array
    {
        return $this->json(data: $this->message->adminData(
            (array)$this->request->post(flags: Filter::NORMAL)
        ));
    }

    public function detail(): array
    {
        $id = (int)$this->request->post('id', Filter::INTEGER);
        return $this->json(data: $this->message->adminDetail($id));
    }

    public function save(): array
    {
        $map = (array)$this->request->post(flags: Filter::NORMAL);
        $data = $this->message->save(
            $this->getManage(),
            $map
        );
        $editing = array_key_exists('id', $map) && (string)$map['id'] !== '0';
        return $this->json(200, $editing ? '消息保存成功' : '消息发送成功', $data);
    }

    public function del(): array
    {
        return $this->json(200, '消息删除成功', $this->message->adminDelete(
            $this->getManage(),
            $this->ids()
        ));
    }

    public function users(): array
    {
        $filter = array_merge(
            (array)$this->request->get(flags: Filter::NORMAL),
            (array)$this->request->post(flags: Filter::NORMAL)
        );
        return $this->json(data: $this->message->users($filter));
    }

    public function audienceCount(): array
    {
        $type = (int)$this->request->post('audience_type', Filter::INTEGER);
        $id = (int)$this->request->post('audience_id', Filter::INTEGER);
        if ($id <= 0) {
            $id = $type === 1
                ? (int)$this->request->post('group_id', Filter::INTEGER)
                : (int)$this->request->post('user_id', Filter::INTEGER);
        }
        return $this->json(data: $this->message->audienceCount($type, $id));
    }

    public function upload(): array
    {
        return $this->json(200, '上传成功', $this->message->upload(
            $this->getManage(),
            (array)$this->request->file('file')
        ));
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
