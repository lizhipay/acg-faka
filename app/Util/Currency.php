<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Config;
use Kernel\Util\Decimal;

/**
 * 站点货币（全站唯一取值/换算点）。
 *
 * 全站的商品定价、订单金额、余额、账单都直接以「站点货币」的数字运作，数据库里的数字
 * 不带币种标记；唯一的换算发生在把金额提交给支付插件的那一刻——所有支付插件都是
 * CNY 语义，toCny() 按配置汇率把站点货币换算成 CNY，换算结果快照进 order/user_recharge
 * 的 gateway_amount 列，回调比对认快照而不是当时汇率。
 *
 * 读取全部带兜底：老站升级后没保存过货币设置时 Config::get() 返回空串，这里回落到
 * CNY/¥/1/2，行为与未引入本类之前逐字节一致。
 */
final class Currency
{
    public const DEFAULT_CODE = 'CNY';
    public const DEFAULT_SYMBOL = '¥';
    public const DEFAULT_RATE = '1';
    public const DEFAULT_DECIMALS = 2;

    /**
     * 货币代码（ISO 风格大写，允许自定义），空/非法回落 CNY。
     * @return string
     */
    public static function code(): string
    {
        $code = strtoupper(trim(Config::get('currency_code')));
        if ($code === '' || !preg_match('/^[A-Z0-9]{1,8}$/', $code)) {
            return self::DEFAULT_CODE;
        }
        return $code;
    }

    /**
     * 货币符号。此值绝不能经过 lang()/t() 翻译（语言包里存在「元→CNY」类映射会污染符号）。
     * @return string
     */
    public static function symbol(): string
    {
        $symbol = trim(Config::get('currency_symbol'));
        return $symbol === '' ? self::DEFAULT_SYMBOL : $symbol;
    }

    /**
     * 汇率：1 站点货币 = rate CNY。保持字符串返回给 bcmath，空/非数字/非正数回落 '1'。
     * @return string
     */
    public static function rate(): string
    {
        $rate = trim(Config::get('currency_rate'));
        if ($rate === '' || !is_numeric($rate) || (float)$rate <= 0) {
            return self::DEFAULT_RATE;
        }
        return $rate;
    }

    /**
     * 显示小数位（仅 0 或 2，纯展示层语义，存储精度不受影响）。
     * @return int
     */
    public static function decimals(): int
    {
        $decimals = trim(Config::get('currency_decimals'));
        return $decimals === '0' ? 0 : self::DEFAULT_DECIMALS;
    }

    /**
     * 是否走旁路：站点货币就是 CNY 或汇率为 1 时不做任何换算，
     * 保证默认配置下与历史行为逐字节一致。
     * @return bool
     */
    public static function isBypass(): bool
    {
        return self::code() === self::DEFAULT_CODE || bccomp(self::rate(), '1', 6) === 0;
    }

    /**
     * 站点货币金额 → 提交给支付网关的 CNY 金额。
     *
     * 旁路时原值直返（不过 bcmath、不做下限钳制）；否则按汇率换算并 half-up 四舍五入到
     * 两位小数：Decimal 是截断语义，所以先用 scale 6 乘出足够精度，+0.005 后取两位即
     * 完成四舍五入（金额恒为正，此法成立）。结果最低 0.01——网关不收 0 元单。
     *
     * @param float|string $amount 站点货币金额
     * @return float CNY 金额（两位小数）
     */
    public static function toCny(float|string $amount): float
    {
        if (self::isBypass()) {
            return (float)$amount;
        }
        $cny = (new Decimal((string)$amount, 6))->mul(self::rate())->add('0.005')->getAmount(2);
        if (bccomp($cny, '0.01', 2) < 0) {
            $cny = '0.01';
        }
        return (float)$cny;
    }

    /**
     * PHP 侧显示格式化：符号 + 千分位 + 按 decimals() 的小数位。
     * @param float|string $amount
     * @param bool $withSymbol
     * @return string
     */
    public static function format(float|string $amount, bool $withSymbol = true): string
    {
        $formatted = number_format((float)$amount, self::decimals());
        return $withSymbol ? self::symbol() . ' ' . $formatted : $formatted;
    }

    /**
     * 注入前端 getVar("CURRENCY") 用的变量包。
     * @return array
     */
    public static function vars(): array
    {
        return [
            'code' => self::code(),
            'symbol' => self::symbol(),
            'rate' => self::rate(),
            'decimals' => self::decimals(),
        ];
    }
}
