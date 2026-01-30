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
     * @param string|float|int $a
     * @param string|float|int $b
     * @param string $op
     * @param int|null $scale
     * @return string
     */
    private function calc(string|float|int $a, string|float|int $b, string $op, ?int $scale = null): string
    {
        $scale ??= $this->scale;

        //先用bcmath
        if (function_exists('bcadd')) {
            return match ($op) {
                'add' => bcadd((string)$a, (string)$b, $scale),
                'sub' => bcsub((string)$a, (string)$b, $scale),
                'mul' => bcmul((string)$a, (string)$b, $scale),
                'div' => bcdiv((string)$a, (string)$b, $scale),
                default => '0',
            };
        }

        $floatResult = match ($op) {
            'add' => (float)$a + (float)$b,
            'sub' => (float)$a - (float)$b,
            'mul' => (float)$a * (float)$b,
            'div' => (float)$a / (float)$b,
            default => 0,
        };

        return number_format($floatResult, $scale, '.', '');
    }

    /**
     * 加法
     * @param string|float|int $other
     * @return Decimal
     */
    public function add(string|float|int $other): Decimal
    {
        $result = $this->calc($this->amount, $other, 'add');
        return new Decimal($result, $this->scale);
    }

    /**
     * 减法
     * @param string|float|int $other
     * @return Decimal
     */
    public function sub(string|float|int $other): Decimal
    {
        $result = $this->calc($this->amount, $other, 'sub');
        return new Decimal($result, $this->scale);
    }

    /**
     * 乘法
     * @param string|float|int $factor
     * @return Decimal
     */
    public function mul(string|float|int $factor): Decimal
    {
        $result = $this->calc($this->amount, $factor, 'mul');
        return new Decimal($result, $this->scale);
    }

    /**
     * 除法
     * @param string|float|int $divisor
     * @return Decimal
     */
    public function div(string|float|int $divisor): Decimal
    {
        $result = $this->calc($this->amount, $divisor, 'div');
        return new Decimal($result, $this->scale);
    }

    /**
     * 获取结果
     * @param int|null $scale
     * @return string
     */
    public function getAmount(?int $scale = 2): string
    {
        return $this->calc($this->amount, '0', 'add', $scale ?? $this->scale);
    }
}