<?php
declare(strict_types=1);

namespace App\Util;

/**
 * Class File
 * @package App\Util
 */
class File
{
    /**
     * 拷贝目录
     * @param string $src 源目录
     * @param string $dst 目标目录
     */
    public static function copyDirectory(string $src, string $dst)
    {
        $dir = opendir($src);
        @mkdir($dst, 0777, true);
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                if (is_dir($src . '/' . $file)) {
                    self::copyDirectory($src . '/' . $file, $dst . '/' . $file);
                    continue;
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
    }
}