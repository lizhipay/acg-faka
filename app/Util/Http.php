<?php
declare(strict_types=1);

namespace App\Util;

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
}