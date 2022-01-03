<?php
declare(strict_types=1);

namespace App\Util;

class Opcache
{

    /**
     * 废除列表
     * @var array
     */
    public static array $invalidate = [];


    /**
     * 重置OP缓存
     */
    public static function reset(): void
    {
        if (extension_loaded("Zend OPcache") || extension_loaded("opcache")) {
            opcache_reset();
        }
    }

    /**
     * 废除脚本缓存
     * @param string ...$file
     */
    public static function invalidate(string ...$file): void
    {
        if (extension_loaded("Zend OPcache") || extension_loaded("opcache")) {
            foreach ($file as $f) {
                opcache_invalidate($f, true);
            }
        }
    }
}