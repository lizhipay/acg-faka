<?php
declare(strict_types=1);

namespace Kernel\Util;

/**
 * Class Context
 * @package Kernel\Util
 */
class Context
{

    /**
     * @var array
     */
    private static array $context = [];


    /**
     * @param string $name
     * @param $value
     */
    public static function set(string $name, $value): void
    {
        self::$context[$name] = $value;
    }

    /**
     * @param string $name
     * @return mixed
     */
    public static function get(string $name)
    {

        return self::$context[$name] ?? null;
    }

    /**
     * @param string $name
     * @return bool
     */
    public static function has(string $name): bool
    {
        return isset(self::$context[$name]);
    }

    /**
     * @param string $name
     * @return void
     */
    public static function del(string $name): void
    {
        unset(self::$context[$name]);
    }
}