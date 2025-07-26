<?php
declare (strict_types=1);

namespace Kernel\Component;


trait Singleton
{
    /**
     * @var mixed
     */
    private static mixed $instance;

    /**
     * @param mixed ...$args
     * @return static
     */
    public static function instance(...$args): static
    {
        if (!isset(static::$instance)) {
            static::$instance = new static(...$args);
        }
        return static::$instance;
    }


    /**
     * @param ...$args
     * @return static
     */
    public static function inst(...$args): static
    {
        return self::instance(...$args);
    }
}