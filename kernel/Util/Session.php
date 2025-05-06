<?php
declare(strict_types=1);

namespace Kernel\Util;

class Session
{
    /**
     * @param string|null $key
     * @return mixed
     */
    public static function get(?string $key = null): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public static function set(string $key, mixed $value): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION[$key] = $value;
        session_write_close();
    }

    /**
     * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * @param string $key
     * @return void
     */
    public static function remove(string $key): void
    {
        if (isset($_SESSION[$key])) {
            session_start();
            unset($_SESSION[$key]);
            session_write_close();
        }
    }

    /**
     * @return void
     */
    public static function clear(): void
    {
        session_destroy();
    }
}