<?php
declare(strict_types=1);

namespace Kernel\Util;


class File
{
    /**
     * 扫描目录
     * @param string $path
     * @param bool $file
     * @return array
     */
    public static function scan(string $path, bool $file = false): array
    {
        $list = scandir($path);
        $dir = [];
        foreach ($list as $item) {
            if ($item != '.' && $item != '..' && ($file || is_dir($path . $item))) {
                $dir[] = $item;
            }
        }
        return $dir;
    }

}