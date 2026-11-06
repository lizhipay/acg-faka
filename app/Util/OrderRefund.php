<?php
declare(strict_types=1);

namespace App\Util;

use App\Consts\Hook;
use App\Model\Bill;
use App\Model\Order;
use App\Model\Pay;
use App\Model\User;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Exception\JSONException;
use Kernel\Util\Decimal;

/**
 * 订单退款（#1145，取代「订单退款」插件）：只退到下单会员的余额，不走支付通道。
 *
 * 一次退款在同一个事务里完成：
 * - 退回买家余额（不计入累计充值），订单记 refund_status=1 / refund_amount / refund_time；
 * - 扣回这单给买家加的元气：通道付款到账时按订单金额加过（余额付款不加），扣到 0 为止；
 * - 原路以硬币扣回卖家（自营商户或分站）收益与推广人分成。对方硬币不够扣时，
 *   由站长在弹窗里当场决定「这一方不扣、差额自己承担」（$skip），否则整单拒绝，绝不留一笔凭空增发的硬币。
 * 统计口径见 Order::scopeUnrefunded()。
 */
final class OrderRefund
{
    public const ROLE_SELLER = 'seller';
    public const ROLE_PROMOTER = 'promoter';

    /**
     * 退款前的预览：金额、手续费、要扣回的分成与对方是否够扣
     * @throws JSONException
     */
    public static function preview(int $orderId): array
    {
        Schema::ensureOrderRefund();
        $order = Order::query()->find($orderId);
        if (!$order) {
            throw new JSONException("订单不存在");
        }
        $buyer = self::assertRefundable($order);

        $payCost = (new Decimal((string)($order->pay_cost ?? 0), 2))->getAmount();
        $amount = (new Decimal((string)$order->amount, 2))->getAmount();
        $clawbacks = [];
        foreach (self::parties($order) as $role => [$userId, $value]) {
            $account = User::query()->find($userId);
            $clawbacks[] = [
                'role' => $role,
                'user_id' => $userId,
                'username' => $account ? (string)$account->username : '',
                'amount' => $value,
                'coin' => $account ? (new Decimal((string)$account->coin, 2))->getAmount() : '0.00',
                'enough' => !$account || bccomp((string)$account->coin, $value, 2) >= 0,
                'exists' => (bool)$account,
            ];
        }

        return [
            'id' => (int)$order->id,
            'trade_no' => (string)$order->trade_no,
            'amount' => $amount,
            'pay_cost' => $payCost,
            'refund_full' => $amount,
            'refund_without_fee' => (new Decimal($amount, 2))->sub($payCost)->getAmount(),
            'buyer' => ['id' => (int)$buyer->id, 'username' => (string)$buyer->username],
            'vigor' => self::vigorDeduction($order, $buyer),
            'clawbacks' => $clawbacks,
        ];
    }

    /**
     * 执行退款
     * @param int $orderId
     * @param bool $refundFee 是否连支付手续费一起退
     * @param string[] $skip 站长决定不扣回的一方（ROLE_*），只在对方硬币不足时才会用到
     * @param string $operator 操作人（写进日志）
     * @return array{refunded: string, vigor: string, clawed: array, skipped: array}
     * @throws JSONException
     */
    public static function run(int $orderId, bool $refundFee, array $skip = [], string $operator = ''): array
    {
        Schema::ensureOrderRefund();
        $skip = array_values(array_intersect($skip, [self::ROLE_SELLER, self::ROLE_PROMOTER]));
        $result = ['refunded' => '0.00', 'vigor' => '0.00', 'clawed' => [], 'skipped' => []];

        $order = DB::transaction(function () use ($orderId, $refundFee, $skip, &$result): Order {
            /** @var Order|null $order */
            $order = Order::query()->lockForUpdate()->find($orderId);
            if (!$order) {
                throw new JSONException("订单不存在");
            }
            self::assertRefundable($order);
            $buyer = User::query()->lockForUpdate()->find((int)$order->owner);

            //order.amount = 商品价 + 支付手续费（issue #932）；手续费已进了支付通道，退不退由站长逐单决定
            $amount = new Decimal((string)$order->amount, 2);
            if (!$refundFee) {
                $amount = $amount->sub((string)($order->pay_cost ?? 0));
            }
            $refunded = $amount->getAmount();
            if (bccomp($refunded, '0', 2) <= 0) {
                throw new JSONException("可退金额为 0，无需退款");
            }

            //带条件的更新：两个人同时点也只会成功一次
            $updated = Order::query()->where('id', $order->id)->where('refund_status', Order::REFUND_NONE)->update([
                'refund_status' => Order::REFUND_DONE,
                'refund_amount' => $refunded,
                'refund_time' => Date::current(),
            ]);
            if ($updated !== 1) {
                throw new JSONException("该订单已经退过款了");
            }

            //退回余额，最后一个 false：不计入累计充值（元气）
            Bill::create($buyer, (float)$refunded, Bill::TYPE_ADD, "订单退款[{$order->trade_no}]", 0, false);

            $vigor = self::vigorDeduction($order, $buyer);
            if (bccomp($vigor, '0', 2) > 0) {
                $buyer->recharge = (new Decimal((string)$buyer->recharge, 2))->sub($vigor)->getAmount();
                $buyer->save();
            }

            $clawed = [];
            $skipped = [];
            foreach (self::parties($order) as $role => [$userId, $value]) {
                $account = User::query()->lockForUpdate()->find($userId);
                if (!$account) {
                    continue; //账户已删除：此前入账随账户一起没了，无从扣回，不阻断退款
                }
                if (bccomp((string)$account->coin, $value, 2) < 0) {
                    if (!in_array($role, $skip, true)) {
                        //预览时还够、提交前对方刚好提现了：拒绝，让站长看到新的余额后重新决定
                        throw new JSONException(self::roleName($role) . "（{$account->username}）的硬币已不足以扣回这单的 {$value}，请重新打开退款窗口确认");
                    }
                    $skipped[] = ['role' => $role, 'user_id' => (int)$account->id, 'amount' => $value];
                    continue;
                }
                Bill::create($account, (float)$value, Bill::TYPE_SUB, "订单退款扣回" . self::roleName($role) . "收益[{$order->trade_no}]", 1, false);
                //入账时累计硬币(total_coin)加过这笔，扣回也要从累计里减掉，否则后台「累计硬币」一直虚高
                $total = (new Decimal((string)$account->total_coin, 2))->sub($value)->getAmount();
                $account->total_coin = bccomp($total, '0', 2) < 0 ? '0.00' : $total;
                $account->save();
                $clawed[] = ['role' => $role, 'user_id' => (int)$account->id, 'amount' => $value];
            }

            $result = ['refunded' => $refunded, 'vigor' => $vigor, 'clawed' => $clawed, 'skipped' => $skipped];
            return $order->refresh();
        });

        //事务提交后再广播，订阅方可能做慢 IO
        hook(Hook::ORDER_REFUND_AFTER, $order, $result);

        $note = "订单退款：{$order->trade_no}，{$result['refunded']} 已退回会员余额";
        if (bccomp((string)($order->pay_cost ?? 0), '0', 2) > 0) {
            $note .= $refundFee ? "（含支付手续费 {$order->pay_cost}）" : "（已扣除支付手续费 {$order->pay_cost}）";
        }
        if (bccomp($result['vigor'], '0', 2) > 0) {
            $note .= "，扣回元气 {$result['vigor']}";
        }
        foreach ($result['clawed'] as $item) {
            $note .= "，扣回" . self::roleName($item['role']) . "#{$item['user_id']} 硬币 {$item['amount']}";
        }
        foreach ($result['skipped'] as $item) {
            $note .= "，" . self::roleName($item['role']) . "#{$item['user_id']} 硬币不足、站长确认不扣回 {$item['amount']}";
        }
        $result['log'] = ($operator !== '' ? $operator . "：" : "") . $note;
        return $result;
    }

    /**
     * @throws JSONException
     */
    private static function assertRefundable(Order $order): User
    {
        if ((int)$order->status !== 1) {
            throw new JSONException("该订单未支付");
        }
        if ((int)($order->refund_status ?? 0) !== Order::REFUND_NONE) {
            throw new JSONException("该订单已经退过款了");
        }
        $buyer = (int)$order->owner > 0 ? User::query()->find((int)$order->owner) : null;
        if (!$buyer) {
            throw new JSONException("游客订单没有余额账户，无法退款");
        }
        return $buyer;
    }

    /**
     * 下单成功时以硬币入账的两方（与 Service\Bind\Order::orderSuccess 同一口径）
     * @return array<string, array{0:int,1:string}>
     */
    private static function parties(Order $order): array
    {
        $parties = [];
        $rebate = (new Decimal((string)($order->rebate ?? 0), 2))->getAmount();
        $sellerId = (int)$order->user_id > 0 ? (int)$order->user_id : (int)$order->substation_user_id;
        if ($sellerId > 0 && bccomp($rebate, '0', 2) > 0) {
            $parties[self::ROLE_SELLER] = [$sellerId, $rebate];
        }
        $divide = (new Decimal((string)($order->divide_amount ?? 0), 2))->getAmount();
        if ((int)$order->from > 0 && bccomp($divide, '0', 2) > 0) {
            $parties[self::ROLE_PROMOTER] = [(int)$order->from, $divide];
        }
        return $parties;
    }

    /** 通道付款到账时给买家加了订单金额的元气（余额付款不加），退款扣回，最多扣到 0 */
    private static function vigorDeduction(Order $order, User $buyer): string
    {
        $pay = Pay::query()->find((int)$order->pay_id);
        if ($pay && (string)$pay->handle === '#system') {
            return '0.00';
        }
        $amount = (new Decimal((string)$order->amount, 2))->getAmount();
        $current = (new Decimal((string)$buyer->recharge, 2))->getAmount();
        if (bccomp($current, '0', 2) <= 0) {
            return '0.00';
        }
        return bccomp($current, $amount, 2) < 0 ? $current : $amount;
    }

    private static function roleName(string $role): string
    {
        return $role === self::ROLE_SELLER ? "卖家" : "推广人";
    }
}
