<?php
declare(strict_types=1);

namespace App\Util;

use App\Entity\Store\Authentication;
use Kernel\Plugin\Plugin;
use Kernel\Util\Aes;
use Kernel\Util\Str;

/**
 *
 */
class Http
{
    /**
     * @param array $opt
     * @return \GuzzleHttp\Client
     */
    public static function make(array $opt = []): \GuzzleHttp\Client
    {
        return new \GuzzleHttp\Client(array_merge(["verify" => false], $opt));
    }

    /**
     * @param string $url
     * @param string $path
     * @param string $method
     * @param array $data
     * @return bool
     */
    public static function download(string $url, string $path, string $method = "GET", array $data = []): bool
    {
        try {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            $options = [
                "verify" => false,
                "sink" => $path,
                "headers" => [
                    "User-Agent" => "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36"
                ]
            ];

            if ($method === "POST" && !empty($data)) {
                $options["form_params"] = $data;
            }

            self::make()->request($method, $url, $options);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

}