<?php
declare (strict_types=1);

namespace App\Util;


class JWT
{

    /**
     * @param string $jwt
     * @return array
     */
    public static function getHead(string $jwt): array
    {
        $arr = explode(".", $jwt);
        if (count($arr) != 3) {
            return [];
        }

        $head = base64_decode($arr[0]);
        return $head ? (array)json_decode($head, true) : [];
    }
}