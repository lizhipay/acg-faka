<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Model\Config;
use App\Model\UserRecharge;
use App\Util\Captcha;
use App\Util\Str;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\Post;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, UserVisitor::class])]
class Order extends User
{
    #[Inject]
    private \App\Service\Order $order;

    /**
     * @return array
     * @throws JSONException
     */
    public function trade(): array
    {
        if (Config::get("trade_verification") == 1) {
            if (!Captcha::check((int)$_POST['captcha'], "trade")) {
                throw new JSONException("验证码错误");
            }
            Captcha::destroy("trade");
        }
        
        hook(\App\Consts\Hook::USER_API_ORDER_TRADE_BEGIN, $_POST);
        $trade = $this->order->trade($this->getUser(), $this->getUserGroup(), $_POST);
        return $this->json(200, '下单成功', $trade);
    }


    /**
     * @return string
     */
    public function callback(): string
    {
        $handle = $_GET['_PARAMETER'][0];
        $data = $_POST;
        if (empty($data)) {
            $data = $_REQUEST;
            unset($data['s']);
        }
        return $this->order->callback($handle, $data);
    }

    /**
     * @param string $tradeNo
     * @return array
     */
    public function state(#[Post] string $tradeNo): array
    {
        $tradeNo = trim($tradeNo);
        $order = \App\Model\Order::query()->where("trade_no", $tradeNo)->first(['id', 'trade_no', 'amount', 'status']);

        if (!$order) {
            $order = UserRecharge::query()->where("trade_no", $tradeNo)->first(['id', 'trade_no', 'amount', 'status']);
        }
        //回显订单信息
        return $this->json(200, 'success', $order->toArray());
    }
}