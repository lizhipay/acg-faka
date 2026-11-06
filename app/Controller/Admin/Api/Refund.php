<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Interceptor\Owner;
use App\Model\ManageLog;
use App\Util\OrderRefund;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

/**
 * 订单退款到会员余额（#1145，取代「订单退款」插件）。
 * 退款直接产生资金流动，只允许站长(type==0)本人操作（同 F-57）。
 */
#[Interceptor([ManageSession::class, Owner::class], Interceptor::TYPE_API)]
class Refund extends Manage
{
    /**
     * @return array
     * @throws JSONException
     */
    public function preview(): array
    {
        return $this->json(data: OrderRefund::preview((int)$this->request->post('order_id')));
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function make(): array
    {
        $orderId = (int)$this->request->post('order_id');
        if ($orderId <= 0) {
            throw new JSONException("订单ID为空");
        }
        $skip = $this->request->post('skip');
        $skip = is_array($skip) ? array_map('strval', $skip) : [];

        $manage = $this->getManage();
        $result = OrderRefund::run($orderId, (string)$this->request->post('refund_fee') === '1', $skip);
        ManageLog::log($manage, $result['log']);

        return $this->json(200, "退款成功，已退回 {$result['refunded']}", [
            'refunded' => $result['refunded'],
            'vigor' => $result['vigor'],
            'clawed' => $result['clawed'],
            'skipped' => $result['skipped'],
        ]);
    }
}
