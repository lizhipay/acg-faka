<?php
declare (strict_types=1);

namespace Kernel\Util;


class Decimal
{
    /**
     * @var string
     */
    private string $amount;

    /**
     * @var int
     */
    private int $scale;

    /**
     * @param string|float|int $amount
     * @param int $scale
     */
    public function __construct(string|float|int $amount, int $scale = 2)
    {
        $this->amount = (string)$amount;
        $this->scale = $scale;
    }

    /**
     * 加法
     * @param string|float|int $other
     * @return Decimal
     */
    public function add(string|float|int $other): Decimal
    {
        $result = bcadd($this->amount, (string)$other, $this->scale);
        return new Decimal($result, $this->scale);
    }

    /**
     * 减法
     * @param string|float|int $other
     * @return Decimal
     */
    public function sub(string|float|int $other): Decimal
    {
        $result = bcsub($this->amount, (string)$other, $this->scale);
        return new Decimal($result, $this->scale);
    }

    /**
     * 乘法
     * @param string|float|int $factor
     * @return Decimal
     */
    public function mul(string|float|int $factor): Decimal
    {
        $result = bcmul($this->amount, (string)$factor, $this->scale);
        return new Decimal($result, $this->scale);
    }

    /**
     * 除法
     * @param string|float|int $divisor
     * @return Decimal
     */
    public function div(string|float|int $divisor): Decimal
    {
        $result = bcdiv($this->amount, (string)$divisor, $this->scale);
        return new Decimal($result, $this->scale);
    }

    /**
     * 获取结果
     * @param int|null $scale
     * @return string
     */
    public function getAmount(?int $scale = 2): string
    {
        return bcadd($this->amount, '0', $scale);
    }
}