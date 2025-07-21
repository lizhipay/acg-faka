<?php
declare(strict_types=1);

namespace Kernel\Util;

class Session
{
    /**
     * @return void
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (headers_sent()) {
                return;
            }
            session_start();
        }
    }

    /**
     * @return void
     */
    public static function end(): void
    {
        session_write_close();
    }


    /**
     * @param string|null $key
     * @return mixed
     */
    public static function get(?string $key = null): mixed
    {
        self::start();
        $result = $_SESSION[$key] ?? null;
        self::end();
        return $result;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
        self::end();
    }

    /**
     * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        self::start();
        $result = isset($_SESSION[$key]);
        self::end();
        return $result;
    }

    /**
     * @param string $key
     * @return void
     */
    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
        self::end();
    }

    /**
     * @return void
     */
    public static function clear(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }
}