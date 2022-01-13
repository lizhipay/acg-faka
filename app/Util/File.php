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
     * @var array
     */
    private static array $cache = [];

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

    /**
     * 删除目录
     * @param string $path
     */
    public static function delDirectory(string $path): void
    {
        if ($handle = opendir($path)) {
            while (false !== ($item = readdir($handle))) {
                if ($item != "." && $item != "..") {
                    if (is_dir("{$path}/{$item}")) {
                        self::delDirectory("{$path}/{$item}");
                    } else {
                        unlink("{$path}/{$item}");
                    }
                }
            }
            closedir($handle);
            rmdir($path);
        }
    }


    /**
     * 缓存文件
     * @param string $path
     * @param bool $cli
     * @return mixed
     */
    public static function codeLoad(string $path, bool $cli = false): mixed
    {

        if ($cli) {
            Opcache::invalidate($path);
            return require($path);
        }

        if (isset(self::$cache[$path])) {
            return self::$cache[$path];
        }
        self::$cache[$path] = require($path);
        return self::$cache[$path];
    }
}