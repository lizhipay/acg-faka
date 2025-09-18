<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Consts\Hook;
use App\Controller\Base\API\User;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Model\Config;
use App\Model\UserRecharge;
use App\Util\Captcha;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\Post;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Waf\Filter;

#[Interceptor([Waf::class, UserVisitor::class])]
class Order extends User
{
    #[Inject]
    private \App\Service\Order $order;

    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     */
    public function trade(Request $request): array
    {
        $map = $request->post(flags: Filter::NORMAL);
        if (Config::get("trade_verification") == 1) {
            if (!Captcha::check((int)$map['captcha'], "trade")) {
                throw new JSONException("验证码错误");
            }
            Captcha::destroy("trade");
        }

        hook(Hook::USER_API_ORDER_TRADE_BEGIN, $map);
        $trade = $this->order->trade($this->getUser(), $this->getUserGroup(), $map);
        return $this->json(200, '下单成功', $trade);
    }


    /**
     * @param Request $request
     * @return string
     */
    public function callback(Request $request): string
    {
        $handle = $_GET['_PARAMETER'][0];
        foreach (['unsafePost', 'unsafeJson', 'unsafeGet'] as $method) {
            $data = $request->$method();
            if (!empty($data)) {
                break;
            }
        }
        if (isset($data['s'])) unset($data['s']);
        if (isset($data['_PARAMETER'])) unset($data['_PARAMETER']);
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