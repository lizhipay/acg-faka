<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Consts\Hook;
use App\Controller\Base\API\User;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Model\Config;
use App\Model\Pay;
use App\Model\UserRecharge;
use App\Util\CallbackIpWhitelist;
use App\Util\Captcha;
use App\Util\Client;
use App\Util\Date;
use App\Util\PayProfile;
use App\Util\PayTest;
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
        CallbackIpWhitelist::enforce();
        //回调URL带的就是订单号
        $tradeNo = (string)($_GET['_PARAMETER'][0] ?? '');
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

        return $this->order->callback($tradeNo, $data);
    }

    /**
     * 拨测专用回调。
     *
     * 跟真实回调走同一套验签机制（同一个 callbackInitialize、同一套配置档），但**绝不碰订单、
     * 卡密和余额**——它只把"网关来过没有、签名对不对"记进 runtime 下的临时文件，
     * 让后台那个拨测面板能显示真实支付状态。
     *
     * 取名 callbackTest 而不是 testCallback，是为了让 Turnstile 那条
     * `str_starts_with($route, 'user/api/order/callback')` 的人机验证豁免自动覆盖到它。
     *
     * @param Request $request
     * @return string
     * @throws JSONException
     */
    public function callbackTest(Request $request): string
    {
        CallbackIpWhitelist::enforce();
        $tradeNo = (string)($_GET['_PARAMETER'][0] ?? '');

        if (!PayTest::isValidTradeNo($tradeNo)) {
            throw new JSONException("fail");
        }

        $record = PayTest::get($tradeNo);
        if ($record === null) {
            //没有对应的拨测记录就直接拒——这个入口只服务于后台点过的那一次拨测
            throw new JSONException("fail");
        }

        //网关成功后往往还会重试几次通知，晚到的坏包不该把已经成功的结果改成失败
        $settled = (string)($record['status'] ?? '') === 'paid';

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
            $settled || PayTest::patch($tradeNo, ['status' => 'failed', 'message' => '网关回调数据为空']);
            throw new JSONException("fail");
        }

        $pay = Pay::query()->find((int)($record['pay_id'] ?? 0));
        if (!$pay) {
            $settled || PayTest::patch($tradeNo, ['status' => 'failed', 'message' => '支付接口已被删除']);
            throw new JSONException("fail");
        }

        try {
            $callback = $this->order->callbackInitialize($pay, $data, PayProfile::config($pay));
        } catch (\Throwable $e) {
            //callbackInitialize 失败时会把真实原因写进该插件的 runtime.log，这里只留个指路
            $settled || PayTest::patch($tradeNo, ['status' => 'failed', 'message' => '验签或状态校验未通过，详见该插件日志']);
            throw new JSONException("fail");
        }

        //与真实回调同样的防重放校验：报文里的订单号必须就是本次拨测这一单
        $verified = (string)($callback['trade_no'] ?? '');
        if ($verified !== '' && !hash_equals($tradeNo, $verified)) {
            $settled || PayTest::patch($tradeNo, ['status' => 'failed', 'message' => '报文订单号与拨测订单号不一致']);
            throw new JSONException("fail");
        }

        $paid = (string)($callback['amount'] ?? '');
        $matched = $paid !== '' && (float)$paid === (float)($record['amount'] ?? 0);

        PayTest::patch($tradeNo, [
            'status' => 'paid',
            'paid_amount' => $paid,
            'pay_time' => Date::current(),
            'message' => $matched ? '' : '金额与拨测金额不一致（真实订单会因此被拒）'
        ]);

        return (string)$callback['success'];
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
