<?php
declare(strict_types=1);

namespace Kernel\Util;

use JetBrains\PhpStorm\NoReturn;

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
    #[NoReturn] public static function set(string $name, $value): void
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
     * @param $unset
     */
    public static function _unset(&$unset)
    {
        unset($unset);
    }
}