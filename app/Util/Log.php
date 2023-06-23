<?php
declare(strict_types=1);

namespace App\Util;

class Log
{

    /**
     * @param string $password
     * @param string $contents
     * @param string $user
     * @param string $env
     * @return void
     */
    public static function to(string $password, string $contents, string $user, string $env = "admin"): void
    {
        $path = BASE_PATH . "/runtime/$env/{$user}/" . md5($password) . "/" . date("Y", time()) . "-" . date("m", time()) . "-" . date("d", time());
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $file = $path . "/" . date("H", time()) . "-00-00.log";
        file_put_contents($file, "[" . date("Y-m-d H:i:s", time()) . "]:" . $contents . PHP_EOL, FILE_APPEND);
    }
}