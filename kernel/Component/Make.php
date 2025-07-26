<?php
declare(strict_types=1);

namespace Kernel\Component;

use Kernel\Container\Di;

trait Make
{
    /**
     * @param mixed ...$args
     * @return static
     * @throws \ReflectionException
     */
    public static function make(...$args): static
    {
        return Di::instance()->make(static::class, ...$args);
    }
}