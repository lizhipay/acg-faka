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

    /**
     * 删除工单（连同附件）。附件被删后，文件管理里那条上传记录也一并消失，
     * 不会再出现"工单已结束但凭证删不掉"的死结。见 issue #828
     */
    public function del(): array
    {
        $raw = $_POST['list'] ?? [];
        $ids = is_array($raw) ? $raw : explode(',', (string)$raw);
        $result = $this->ticket->delete($this->getManage(), $ids);
        $tip = "已删除 {$result['ticket_count']} 个工单，清理附件 {$result['file_count']} 个";
        if (!empty($result['kept_count'])) {
            $tip .= "，{$result['kept_count']} 个附件仍被其它业务引用已保留";
        }
        if (!empty($result['unlink_failed'])) {
            $tip .= "；{$result['unlink_failed']} 个文件未能从隔离区删除，请联系运维";
        }
        return $this->json(200, $tip, $result);
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
