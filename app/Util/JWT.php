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

        $segment = strtr($arr[0], '-_', '+/');
        $segment .= str_repeat('=', (4 - strlen($segment) % 4) % 4);
        $head = base64_decode($segment, true);
        if (!is_string($head) || $head === '') {
            return [];
        }
        $decoded = json_decode($head, true);
        return is_array($decoded) ? $decoded : [];
    }
}
