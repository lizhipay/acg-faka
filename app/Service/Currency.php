<?php
declare(strict_types=1);

namespace App\Service;

use Kernel\Annotation\Bind;

/**
 * 站点货币 Service。
 *
 * 汇率本身是站长手动维护的（后台「系统设置→其他设置」），这个接口是给插件用的
 * 程序化入口——比如以后写一个定时同步汇率的插件，注入本服务调 setRate() 即可，
 * 不需要自己碰 config 表。写入走 Config::putMany 的锁与缓存一致性机制。
 *
 * 注意：改汇率只影响之后新提交的支付（换算结果在提交网关时快照进订单），
 * 已下单/已回调的订单不受影响。
 */
#[Bind(class: \App\Service\Bind\Currency::class)]
interface Currency
{

    /**
     * 当前货币配置。
     * @return array{code: string, symbol: string, rate: string, decimals: int}
     */
    public function getCurrency(): array;

    /**
     * 当前汇率（1 站点货币 = rate CNY），字符串形式。
     * @return string
     */
    public function getRate(): string;

    /**
     * 更新汇率。非法值（非数字、<=0、超过 6 位小数）抛异常。
     * @param string|float $rate
     */
    public function setRate(string|float $rate): void;

    /**
     * 整体切换货币。纯重标注：不会换算库里任何已有金额数字。
     * @param string $code 货币代码，大写字母/数字 1-8 位
     * @param string $symbol 货币符号，1-8 字符，禁止 HTML 特殊字符与引号
     * @param string|float $rate 1 站点货币 = rate CNY
     * @param int|null $decimals 显示小数位（0 或 2），null 保持现值
     */
    public function setCurrency(string $code, string $symbol, string|float $rate, ?int $decimals = null): void;

    /**
     * 站点货币金额 → CNY（与支付提交用的是同一套换算）。
     * @param float|string $amount
     * @return float
     */
    public function toCny(float|string $amount): float;

    /**
     * 切换币种并按汇率换算全站金额数据（一次性、不可逆的重定价）。
     *
     * 换算因子 = 当前汇率 ÷ 目标汇率（都以「1 单位 = X 人民币」计）。覆盖：会员余额/硬币/
     * 累计值、账单、历史订单、充值单、提现单、商品价格（含种类/批发/SKU/成本等序列化配置与
     * 会员等级定制价）、卡密溢价、优惠券固定抵扣、商户等级售价、会员等级门槛、加价模板固定值、
     * 分站加价、充值与提现阈值、充值赠送规则。百分比类字段与 gateway_amount（CNY 快照）不动。
     * 换算完成后写入新的货币配置并记录审计日志。
     *
     * @param string $code 目标货币代码（必须不同于当前代码）
     * @param string $symbol 目标货币符号
     * @param string|float $rate 目标汇率
     * @param int|null $decimals 显示小数位（0/2），null 保持现值
     * @return array 各表换算行数汇总
     */
    public function convertAll(string $code, string $symbol, string|float $rate, ?int $decimals = null): array;
}
