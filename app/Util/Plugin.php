<?php
declare(strict_types=1);

namespace App\Util;

use Kernel\Exception\JSONException;

/**
 * Class Plugin
 * @package App\Util
 */
class Plugin
{
    /**
     * @param string $pluginName
     * @param string $db
     * @param string $key
     * @param mixed $value
     * @param int $expire
     * @throws \Kernel\Exception\JSONException
     */
    public static function setCache(string $pluginName, string $db, string $key, mixed $value, int $expire = 0): void
    {
        $path = BASE_PATH . '/app/Plugin/' . $pluginName . '/Db/';
        $db = $path . $db . ".php";
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $data = [];
        if (file_exists($db)) {
            $data = (array)File::codeLoad($db);
        }

        $data[$key] = serialize([
            'data' => $value,
            'expire' => $expire == 0 ? 0 : time() + $expire
        ]);
        setConfig($data, $db);
    }

    /**
     * @param string $pluginName
     * @param string $db
     * @param string $key
     * @return mixed
     * @throws JSONException
     */
    public static function getCache(string $pluginName, string $db, string $key): mixed
    {
        $path = BASE_PATH . '/app/Plugin/' . $pluginName . '/Db/' . $db . ".php";

        if (!file_exists($path)) {
            return null;
        }

        $data = (array)File::codeLoad($path);

        if (!isset($data[$key])) {
            return null;
        }

        $unserialize = unserialize($data[$key]);
        if ($unserialize['expire'] != 0 && $unserialize['expire'] < time()) {
            self::delCache($pluginName, $db, $key);
            return null;
        }

        return $unserialize['data'];
    }

    /**
     * @param string $pluginName
     * @param string $db
     * @return array
     * @throws JSONException
     */
    public static function getCaches(string $pluginName, string $db): array
    {
        $path = BASE_PATH . '/app/Plugin/' . $pluginName . '/Db/' . $db . ".php";
        if (!file_exists($path)) {
            return [];
        }
        $data = (array)File::codeLoad($path);
        $success = [];
        foreach ($data as $key => $val) {
            $unserialize = unserialize($val);
            if ($unserialize['expire'] != 0 && (int)$unserialize['expire'] < time()) {
                self::delCache($pluginName, $db, $key);
                continue;
            }
            $success[$key] = $unserialize['data'];
        }
        return $success;
    }

    /**
     * @throws JSONException
     */
    public static function delCache(string $pluginName, string $db, string $key): void
    {
        $path = BASE_PATH . '/app/Plugin/' . $pluginName . '/Db/' . $db . ".php";
        if (!file_exists($path)) {
            return;
        }
        $data = (array)File::codeLoad($path);
        unset($data[$key]);

        if (count($data) == 0) {
            unlink($path);
            return;
        }

        setConfig($data, $db);
    }

    /**
     * @param string $pluginName
     * @param string $db
     */
    public static function clearCache(string $pluginName, string $db)
    {
        $path = BASE_PATH . '/app/Plugin/' . $pluginName . '/Db/' . $db . ".php";
        if (!file_exists($path)) {
            return;
        }
        unlink($path);
    }


    /**
     * @param string $pluginName
     * @return array
     */
    public static function getConfig(string $pluginName): array
    {
        $path = BASE_PATH . '/app/Plugin/' . $pluginName . '/Config/Config.php';
        if (!file_exists($path)) {
            return [];
        }
        return (array)File::codeLoad($path);
    }
}