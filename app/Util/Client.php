<?php
declare(strict_types=1);

namespace App\Util;

use JetBrains\PhpStorm\NoReturn;
use Kernel\Util\View;

/**
 * Class Client
 * @package App\Util
 */
class Client
{
    /*
     * 获取客户端IP地址
     * @return string
     */
    public static function getAddress(): string
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $arr = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return (string)$arr[0];
        }

        return (string)$_SERVER['REMOTE_ADDR'];
    }

    /**
     * 获取URL地址
     * @return string
     */
    public static function getUrl(): string
    {
        if ($_SERVER["HTTPS"] == "on") {
            $_SERVER['REQUEST_SCHEME'] = "https";
        }
        return $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'];
    }
 
    /**
     * @return string
     */
    public static function getDomain(): string
    {
        $host = explode(":", (string)$_SERVER['HTTP_HOST']);
        return (string)$host[0];
    }

    /**
     * 重定向浏览器地址
     * @param string $url
     * @param string $message
     * @param int $time
     */
    #[NoReturn] public static function redirect(string $url, string $message, int $time = 2): void
    {
        header("refresh:{$time},url={$url}");
        echo View::render("404.html", ["msg" => $message]);
        exit;
    }
}