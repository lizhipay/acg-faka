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
use App\Util\Client;
use App\Util\Str;
use App\Util\Throttle;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\Post;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Util\Arr;
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

        $map['device'] = Client::getDeviceTypeByUa($request->header("User-Agent"));

        hook(Hook::USER_API_ORDER_TRADE_BEGIN, $map);
        $trade = $this->order->trade($this->getUser(), $this->getUserGroup(), $map);
        return $this->json(200, '下单成功', $trade);
    }


    /**
     * @param Request $request
     * @return string
     * @throws JSONException
     */
    public function callback(Request $request): string
    {
        $handle = $_GET['_PARAMETER'][0];
        foreach (['unsafePost', 'unsafeJson', 'unsafeGet'] as $method) {
            $data = $request->$method();
            if (isset($data['s'])) unset($data['s']);
            if (isset($data['_PARAMETER'])) unset($data['_PARAMETER']);

            if (!empty($data)) {
                break;
            }
        }

        if (empty($data)) {
            $data = json_decode($request->raw(), true);
        }

        if (empty($data)) {
            $data = Arr::xmlToArray((string)file_get_contents("php://input"));
        }

        if (empty($data)) {
            throw new JSONException("数据为空");
        }

        if (isset($data['sign']) && Str::isInvalidSign($data['sign'])) {
            throw new JSONException("非法签名");
        }

        if (isset($data['signature']) && Str::isInvalidSign($data['signature'])) {
            throw new JSONException("非法签名");
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
        //宽松限流：允许正常轮询支付状态，挡住大批量订单枚举
        if (Throttle::tooMany("state:ip:" . Client::getAddress(), 120, 600)) {
            throw new JSONException("请求过于频繁，请稍后再试");
        }
        $order = \App\Model\Order::query()->where("trade_no", $tradeNo)->first(['id', 'trade_no', 'amount', 'status']);
        if (!$order) {
            $order = UserRecharge::query()->where("trade_no", $tradeNo)->first(['id', 'trade_no', 'amount', 'status']);
        }
        if (!$order) {
            //原代码对不存在订单会 $order->toArray() 空指针报错（曾刷满 runtime.log）
            throw new JSONException("未查询到相关信息");
        }
        //回显订单信息
        return $this->json(200, 'success', $order->toArray());
    }
}