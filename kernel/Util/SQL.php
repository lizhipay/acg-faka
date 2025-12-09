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
    public static function import(string $sql, string $host, string $db, string $username, string $password, string $prefix, string $driver = 'mysql', ?int $port = null)
    {
        //处理前缀
        $sqlSrc = str_replace('__PREFIX__', $prefix, (string)file_get_contents($sql));
        if ($sqlSrc == "") {
            return;
        }

        try {
            if ($driver === 'pgsql') {
                $dsn = 'pgsql:host=' . $host . ';port=' . ($port ?? 5432) . ';dbname=' . $db;
                $pdo = new \PDO($dsn, $username, $password);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $statements = array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sqlSrc)));
                foreach ($statements as $statement) {
                    if ($statement !== '') {
                        $pdo->exec($statement);
                    }
                }
            } else {
                if (file_put_contents($sql . '.process', $sqlSrc) === false) {
                    throw new JSONException("没有写入权限，请检查权限是否足够");
                }

                $tmp = BASE_PATH . '/runtime/tmp';
                if (!is_dir($tmp)) {
                    mkdir($tmp, 0777, true);
                }

                $dsn = 'mysql:dbname=' . $db . ';host=' . $host;
                if (!empty($port)) {
                    $dsn .= ';port=' . $port;
                }

                $dump = new Dump();
                $dump
                    ->file($sql . '.process')
                    ->dsn($dsn)
                    ->user($username)
                    ->pass($password)
                    ->tmp($tmp);
                new Import($dump);
                unlink($sql . '.process');
            }
        } catch (\Exception $e) {
            throw new JSONException("数据库出错，原因：" . $e->getMessage());
        }
    }

    public static function getDriver(): string
    {
        if (file_exists(BASE_PATH . '/kernel/Install/pg.lock')) {
            return 'pgsql';
        }

        if (file_exists(BASE_PATH . '/kernel/Install/mysql.lock')) {
            return 'mysql';
        }

        $database = config('database');
        return $database['driver'] ?? 'mysql';
    }
}