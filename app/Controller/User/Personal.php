<?php
declare(strict_types=1);

namespace App\Controller\User;

use App\Controller\Base\View\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, UserSession::class])]
class Personal extends User
{
    /**
     * 购买记录
     * @return string
     * @throws \Kernel\Exception\ViewException
     * @throws \ReflectionException
     */
    public function purchaseRecord(): string
    {
        $tradeNo = (string)$_GET['tradeNo'];
        return $this->theme("购买记录", "PURCHASE_RECORD", "User/PurchaseRecord.html", ['tradeNo' => $tradeNo]);
    }

    /**
     * 下载宝贝信息
     * @throws \Kernel\Exception\JSONException
     */
    public function secretDownload(): string
    {
        $id = (int)$_GET['id'];

        $order = \App\Model\Order::query()->where("owner", $this->getUser()->id)->find($id);

        if (!$order) {
            throw new JSONException("订单不存在");
        }
        header('Content-Type:application/octet-stream');
        header('Content-Transfer-Encoding:binary');
        header('Content-Disposition:attachment; filename=宝贝信息-' . $order->trade_no . '.txt');
        return (string)$order->secret;
    }
}