<?php
declare(strict_types=1);

namespace App\Util;

use Kernel\Exception\JSONException;

class Ini
{

    /**
     * @param array $a1
     * @param array $a2
     * @return array
     */
    private static function arrayMerge(array $a1, array $a2): array
    {
        $arr = $a1 + $a2;
        foreach ($arr as $k => $v) {
            if (is_array($v) && isset($a1[$k]) && isset($a2[$k])) {
                $arr[$k] = self::arrayMerge($a1[$k], $a2[$k]);
            }
        }
        return $arr;
    }

    /**
     * @param $src
     * @param array $link
     * @param string $value
     */
    private static function parseObj(&$src, array $link, string $value)
    {
        if (count($link) <= 0) {
            $src = $value;
            return;
        }
        //拿到第一个key
        $shift = array_shift($link);
        //判断当前链式是否带[]
        if (str_contains($shift, '[]')) {
            //数组解析，创建数组
            $key = str_replace("[]", "", $shift);
            $src[$key][] = [];
            $index = count($src[$key]) - 1;
            self::parseObj($src[$key][$index], $link, $value);
            //解析对象
        } else {
            $src[$shift] = [];
            self::parseObj($src[$shift], $link, $value);
        }
    }

    /**
     * @param string $content
     * @return array
     * @throws JSONException
     */
    public static function toArray(string $content): array
    {
        $data = preg_split('/[\r\n]+/s', trim($content));
        $list = [];
        $nodeName = "";
        foreach ($data as $var) {
            if (empty($var)) {
                continue;
            }
            preg_match('#\\[(.*?)\\]$#', $var, $match);
            if (isset($match[1])) {
                $nodeName = $match[1];
                if (!array_key_exists($nodeName, $list)) {
                    $list[$nodeName] = [];
                }
            } else {
                if (!empty($nodeName)) {
                    $temporary = explode('=', $var);
                    if (count($temporary) != 2) {
                        throw new JSONException('配置解析异常，' . $var . ' 没有赋值');
                    }

                    $left = $temporary[0];
                    $leftParse = explode(".", $left);
                    $src = [];
                    self::parseObj($src, $leftParse, $temporary[1]);
                    $list[$nodeName] = self::arrayMerge($list[$nodeName], $src);
                } else {
                    throw new JSONException("配置解析异常，{$var} 不能没有节点");
                }
            }
        }
        return $list;
    }


    /**
     * @param array $config
     * @param string|null $prefix
     * @return string
     */
    private static function parseContent(array $config, ?string $prefix = null): string
    {
        $cfg = "";
        foreach ($config as $key => $val) {
            if (is_array($val)) {
                $cfg .= self::parseContent($val, $prefix ? $prefix . "." . $key : $key);
            } else {
                //不是数组
                $cfg .= ($prefix ? $prefix . "." : "") . $key . "=" . $val . PHP_EOL;
            }
        }

        return $cfg;
    }

    /**
     * @param array $config
     * @return string
     */
    public static function toConfig(array $config): string
    {
        $cfg = "";
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $cfg .= "[{$key}]" . PHP_EOL;
                $cfg .= self::parseContent($value);
            }
        }
        return trim($cfg);
    }
}