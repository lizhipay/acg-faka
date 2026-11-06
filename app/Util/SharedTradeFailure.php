<?php
declare(strict_types=1);

namespace App\Util;

use Kernel\Exception\JSONException;

/**
 * 向上游下单失败，分三类（#1172）：
 *   - REJECTED：上游明确拒绝（库存不足、价格变了……），上游那边没有成交；
 *   - UNCERTAIN：连不上、超时、回应不是正常的 JSON，上游可能已经成交了；
 *   - DUPLICATE：上游说这个请求号下过单了，也就是上游已经成交、只是本站没拿到结果。
 *
 * 继承 JSONException，所以原来只认 JSONException 的地方照旧；message 仍是给外人看的安全文案，
 * detail 是给站长排查的原文。
 */
final class SharedTradeFailure extends JSONException
{
    public const REJECTED = 'rejected';
    public const UNCERTAIN = 'uncertain';
    public const DUPLICATE = 'duplicate';

    private string $kind;
    private string $detail;

    public function __construct(string $message, string $kind, string $detail = '')
    {
        parent::__construct($message);
        $this->kind = $kind;
        $this->detail = $detail !== '' ? $detail : $message;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function detail(): string
    {
        return $this->detail;
    }

    /** 上游是不是可能已经成交了（不能当作「没买到」直接作废） */
    public function mayHaveSucceeded(): bool
    {
        return $this->kind !== self::REJECTED;
    }
}
