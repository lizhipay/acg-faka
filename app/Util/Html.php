<?php
declare(strict_types=1);

namespace App\Util;

final class Html implements \JsonSerializable
{
    public string $raw;

    public function __construct(string $raw)
    {
        $this->raw = $raw;
    }

    public static function trusted(mixed $value, bool $trusted): mixed
    {
        if (!$trusted || !is_string($value) || $value === '') {
            return $value;
        }
        return new self($value);
    }

    public function jsonSerialize(): string
    {
        return $this->raw;
    }

    public function __toString(): string
    {
        return $this->raw;
    }
}
