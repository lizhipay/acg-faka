<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Pay;
use Kernel\Exception\JSONException;
use Kernel\Util\Decimal;

/**
 * 支付接口单笔限额（#1129）。
 * 按含手续费的实付金额判断（网关收到的就是这个数），商品下单与余额充值共用同一组限额；
 * 余额支付（#system）和 0 元单不受限。前台按同一公式预先置灰，服务端以这里为准。
 */
final class PayLimit
{
    /** 前台支付方式列表多带的字段：限额与手续费（前端按同一公式算实付金额） */
    public const LIST_COLUMNS = ['min_amount', 'max_amount', 'cost', 'cost_type'];

    /**
     * 支付方式列表出口：把限额/手续费整理成数字，缺列（老库、插件追加的条目）按不限处理
     * @param array $list
     * @return array
     */
    public static function present(array $list): array
    {
        foreach ($list as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $item['min_amount'] = (float)($item['min_amount'] ?? 0);
            $item['max_amount'] = (float)($item['max_amount'] ?? 0);
            $item['cost'] = (float)($item['cost'] ?? 0);
            $item['cost_type'] = (int)($item['cost_type'] ?? 0);
        }
        return $list;
    }

    /**
     * @param Pay $pay
     * @param string|float $payable 含手续费的实付金额（站点货币）
     * @throws JSONException
     */
    public static function assert(Pay $pay, string|float $payable): void
    {
        $message = self::violation($pay, $payable);
        if ($message !== null) {
            throw new JSONException($message);
        }
    }

    /**
     * @param Pay $pay
     * @param string|float $payable
     * @return string|null 超限时的提示，未超限返回 null
     */
    public static function violation(Pay $pay, string|float $payable): ?string
    {
        if ((string)$pay->handle === '#system') {
            return null;
        }
        $amount = (new Decimal((string)$payable, 2))->getAmount();
        if (bccomp($amount, '0', 2) <= 0) {
            return null;
        }

        $min = self::money($pay->min_amount ?? 0);
        $max = self::money($pay->max_amount ?? 0);
        if (bccomp($min, '0', 2) > 0 && bccomp($amount, $min, 2) < 0) {
            return self::message($pay, lang("单笔最低"), $min, $amount);
        }
        if (bccomp($max, '0', 2) > 0 && bccomp($amount, $max, 2) > 0) {
            return self::message($pay, lang("单笔最高"), $max, $amount);
        }
        return null;
    }

    /** 实付金额 = 金额 + 手续费（与 Service\Bind\Order / Recharge 计算 pay_cost 的口径一致） */
    public static function payable(Pay $pay, string|float $amount): string
    {
        $base = new Decimal((string)$amount, 2);
        $fee = (int)$pay->cost_type === 0
            ? (new Decimal((string)$pay->cost, 2))->getAmount()
            : $base->mul((string)$pay->cost)->getAmount();
        return $base->add($fee)->getAmount();
    }

    private static function message(Pay $pay, string $label, string $limit, string $amount): string
    {
        $name = \Kernel\Util\Lang::trans((string)$pay->name, "dyn");
        return $name . " " . $label . " " . Currency::format($limit) . "，"
            . lang("本次需支付") . " " . Currency::format($amount) . "，" . lang("请更换其他支付方式");
    }

    private static function money(mixed $value): string
    {
        $value = trim((string)$value);
        if (!preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
            return '0.00';
        }
        return (new Decimal($value, 2))->getAmount();
    }
}
