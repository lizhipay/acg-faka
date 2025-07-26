<?php
declare (strict_types=1);

namespace Kernel\Util;

class Arr
{

    /**
     * 通过链式获取数组中的内容
     * @param array $arr
     * @param string|null $chain
     * @return mixed
     */
    public static function get(array $arr, ?string $chain): mixed
    {
        if (!$chain) {
            return $arr;
        }

        $keys = explode('.', trim($chain));
        foreach ($keys as $key) {
            if (isset($arr[$key])) {
                $arr = $arr[$key];
            } else {
                return null;
            }
        }
        return $arr;
    }


    /**
     * @param string $chain
     * @return string
     */
    public static function getChainFirst(string $chain): string
    {
        $keys = explode('.', trim($chain));
        return (string)$keys[0];
    }


    /**
     * @param string $chain
     * @return string|null
     */
    public static function getChainIgnoreFirst(string $chain): ?string
    {
        $keys = explode('.', trim($chain));
        if (count($keys) <= 1) {
            return null;
        }
        array_shift($keys);
        return implode('.', $keys);
    }

    /**
     * @param string $str
     * @param string $separator
     * @return array
     */
    public static function strToList(string $str, string $separator = "\n"): array
    {
        $list = explode($separator, $str);
        return array_values(array_filter(array_map(function ($item) {
            $item = trim($item);
            // 过滤掉空字符串和注释
            return ($item === '' || str_starts_with($item, '#') || str_starts_with($item, '//')) ? null : $item;
        }, $list)));
    }


    /**
     * @param string $str
     * @return array
     */
    public static function xmlToArray(string $str): array
    {
        // 解析XML字符串
        $xml = simplexml_load_string($str, 'SimpleXMLElement', LIBXML_NOCDATA);
        // 检查解析结果
        if ($xml === false) {
            return [];
        }
        return (array)json_decode(json_encode($xml), true) ?: [];
    }

}