<?php
declare(strict_types=1);

namespace App\Util;


class PayConfig
{
    /**
     * @param string $handle
     * @return array|null
     */
    public static function config(string $handle): ?array
    {
        return require(BASE_PATH . '/app/Pay/' . $handle . '/Config/Config.php');
    }

    /**
     * @param string $handle
     * @return array|null
     */
    public static function info(string $handle): ?array
    {
        return require(BASE_PATH . '/app/Pay/' . $handle . '/Config/Info.php');
    }


    /**
     * @param string $handle
     * @param string $type
     * @param string $message
     */
    public static function log(string $handle, string $type, string $message): void
    {
        $path = BASE_PATH . "/app/Pay/{$handle}/runtime.log";
        file_put_contents($path, "[{$type}][" . Date::current() . "]:" . $message . PHP_EOL, FILE_APPEND);
    }
}