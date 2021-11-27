<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Controller\Base\View\User;
use App\Interceptor\Waf;
use App\Model\Order;
use App\Model\OrderOption;
use Kernel\Annotation\Get;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Util\View;

#[Interceptor(Waf::class)]
class Pay extends User
{
    /**
     * @return string
     * @throws \Kernel\Exception\JSONException|\Kernel\Exception\ViewException
     */
    public function order(): string
    {
        $obj = [];
        parse_str(base64_decode(urldecode((string)$_GET['_PARAMETER'][0])), $obj);
        //获取订单信息
        $order = Order::query()->where("trade_no", $obj['tradeNo'])->first();
        if (!$order) {
            return '订单不存在';
        }
        $type = (int)$obj['type'];
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

        return View::render($obj['handle'] . '/View/' . $obj['code'] . '.html', ['order' => $order, 'option' => $data], BASE_PATH . '/app/Pay/');
    }
}