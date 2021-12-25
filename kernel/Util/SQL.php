<?php
declare(strict_types=1);

namespace Kernel\Util;


use Kernel\Exception\JSONException;
use Rah\Danpu\Dump;
use Rah\Danpu\Import;

class SQL
{
    /**
     * @throws \Kernel\Exception\JSONException
     */
    public static function import(string $sql, string $host, string $db, string $username, string $password, string $prefix)
    {
        //处理前缀
        $sqlSrc = str_replace('__PREFIX__', $prefix, (string)file_get_contents($sql));
        if ($sqlSrc == "") {
            return;
        }
        if (file_put_contents($sql . '.process', $sqlSrc) === false) {
            throw new JSONException("没有写入权限，请检查权限是否足够");
        }

        $tmp = BASE_PATH . '/runtime/tmp';
        if (!is_dir($tmp)) {
            mkdir($tmp, 0777, true);
        }

        $dump = new Dump();
        $dump
            ->file($sql . '.process')
            ->dsn('mysql:dbname=' . $db . ';host=' . $host)
            ->user($username)
            ->pass($password)
            ->tmp($tmp);
        try {
            new Import($dump);
            unlink($sql . '.process');
        } catch (\Exception $e) {
            throw new JSONException("数据库出错，原因：" . $e->getMessage());
        }
    }
}