<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Controller\Base\View\User;
use App\Interceptor\Waf;
use App\Model\Order;
use App\Model\OrderOption;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;
use Kernel\Util\View;

#[Interceptor(Waf::class)]
class Pay extends User
{
    /**
     * @return string
     * @throws JSONException
     * @throws ViewException
     * @throws \SmartyException
     */
    public function order(): string
    {
        if (!isset($_GET['_PARAMETER'][0]) || !isset($_GET['_PARAMETER'][1])) {
            return '订单不存在';
        }

        $tradeNo = $_GET['_PARAMETER'][0];
        $type = (int)$_GET['_PARAMETER'][1];
        //获取订单信息
        $order = Order::with(['pay'])->where("trade_no", $tradeNo)->first();
        if (!$order) {
            return '订单不存在';
        }

        if (!$order->pay) {
            return '支付方式不存在';
        }

        $data = OrderOption::get($order->id);

        if ($type == 2) {
            if (!$data) {
                throw new JSONException("参数错误");
            }
            return $this->render("正在下单，请稍后..", "Submit.html", [
                "url" => $order->pay_url,
                "data" => $data
            ]);
        }

        $html = "{$order->pay->handle}/View/{$order->pay->code}.html";

        if (!is_file(BASE_PATH . '/app/Pay/' . $html)) {
            throw new JSONException("视图不存在");
        }

        return View::render($html, ['order' => $order, 'option' => $data], BASE_PATH . '/app/Pay/');
    }
}