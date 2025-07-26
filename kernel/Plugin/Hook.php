<?php
declare (strict_types=1);

namespace Kernel\Plugin;

use App\Util\Client;
use Kernel\Component\Singleton;
use Kernel\Util\Binary;
use Kernel\Util\File;
use Kernel\Util\Plugin;

class Hook
{

    use Singleton;

    public const CACHE_FILE = BASE_PATH . "/runtime/plugin/hook";


    /**
     * @return void
     */
    public function load(): void
    {
        $path = BASE_PATH . "/runtime/plugin/";
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        if (!is_writable(Hook::CACHE_FILE)) {
            return;
        }

        $hooks = File::read(Hook::CACHE_FILE, function (string $contents) {
            return Binary::inst()->unpack($contents, _plugin_get_hwid());
        }) ?: [];

        foreach ($hooks as $points) {
            foreach ($points as $a => $point) {
                foreach ($point as $plugin) {
                    Plugin::$container['hook'][$a][] = ["namespace" => $plugin['namespace'], "method" => $plugin['method'], "pluginName" => $plugin['pluginName']];
                }
            }
        }

        $route = explode("/", trim($_GET['s'], "/"));
        if (strtolower($route[0]) == "plugin") {
            $pluginName = ucfirst($route[1]);
            $pluginCfg = Plugin::getPlugin($pluginName);
            if ($pluginCfg['PLUGIN_CONFIG']['STATUS'] != 1) {
                Client::redirect("/", "当前插件未启用");
            }
        }
    }

    /**
     * @param string $name
     * @return void
     */
    public function del(string $name): void
    {
        _plugin_hook_del($name);
    }


    /**
     * @param string $name
     * @return void
     */
    public function add(string $name): void
    {
        _plugin_hook_add($name);
    }


    /**
     * @param string $name
     * @param int $point
     * @param string $namespace
     * @param string $method
     * @return bool
     */
    public function exist(string $name, int $point, string $namespace, string $method): bool
    {
        return _plugin_hook_exist($name, $point, $namespace, $method);
    }
}