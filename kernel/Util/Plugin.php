<?php
declare(strict_types=1);

namespace Kernel\Util;


use App\Util\Opcache;
use Kernel\Consts\Base;
use Kernel\Container\Di;

class Plugin
{
    /**
     * @var array
     */
    public static array $container = [];

    /**
     * @var string|null
     */
    public static ?string $currentPluginName = null;

    /**
     * @var string|null
     */
    public static ?string $currentControllerPluginName = null;

    /**
     * @param string $name
     * @param bool $cache
     * @return array|null
     */
    public static function getPlugin(string $name, bool $cache = true): ?array
    {
        $path = BASE_PATH . "/app/Plugin/{$name}";

        $infoPath = $path . '/Config/Info.php';
        $submitPath = $path . '/Config/Submit.php';
        $configPath = $path . '/Config/Config.php';
        if (!file_exists($infoPath) || !file_exists($submitPath) || !file_exists($configPath)) {
            return null;
        }

        if (!$cache) {
            Opcache::invalidate($infoPath, $submitPath, $configPath);
        }

        $info = (array)require($infoPath);
        $submit = (array)require($submitPath);
        $config = (array)require($configPath);

        //submit
        if (is_array($submit)) {
            foreach ($submit as $index => $item) {
                if (isset($config[$item['name']])) {
                    $submit[$index]['default'] = ($item['name'] == \App\Consts\Plugin::STATUS ? (int)$config[$item['name']] : $config[$item['name']]);
                }
            }
        }
        $info[\App\Consts\Plugin::PLUGIN_SUBMIT] = $submit;
        $info[\App\Consts\Plugin::PLUGIN_CONFIG] = $config;
        return $info;
    }

    /**
     * @param bool $cache
     * @return array|null
     */
    public static function getPlugins(bool $cache = true): ?array
    {
        $path = BASE_PATH . "/app/Plugin/";
        $scan = File::scan($path);
        $plugins = [];
        foreach ($scan as $item) {
            $plugin = self::getPlugin($item, $cache);
            if ($plugin) {
                $plugin[\App\Consts\Plugin::PLUGIN_NAME] = $item;
                $plugins[] = $plugin;
            }
        }
        return $plugins;
    }

    /**
     * @param string $pluginName
     * @param int $state
     * @param mixed ...$args
     * @throws \ReflectionException
     */
    public static function runHookState(string $pluginName, int $state, mixed ...$args): void
    {
        //扫描目标目录文件
        $path = BASE_PATH . "/app/Plugin/{$pluginName}/Hook/";
        //扫描插件的hook
        $hookScan = File::scan($path, true);
        foreach ($hookScan as $class) {
            $_class = explode(".", $class);
            $_className = trim((string)$_class[0]);
            $namespace = "\\App\\Plugin\\{$pluginName}\\Hook\\{$_className}";
            if (class_exists($namespace)) {
                $reflectionClass = new \ReflectionClass(objectOrClass: $namespace);
                foreach ($reflectionClass->getMethods() as $method) {
                    $reflectionMethod = new \ReflectionMethod($namespace, $method->getName());
                    $reflectionAttributes = $reflectionMethod->getAttributes();
                    foreach ($reflectionAttributes as $attribute) {
                        $arguments = $attribute->getArguments();
                        if ($attribute->newInstance() instanceof \Kernel\Annotation\Plugin) {
                            if ($arguments['state'] == $state) {
                                call_user_func_array([new $namespace, $method->getName()], $args);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @param int $point
     * @param mixed ...$args
     * @return void|mixed
     * @throws \ReflectionException
     */
    public static function hook(int $point, mixed &...$args)
    {
        if (Context::get(Base::STORE_STATUS) && Context::get(Base::IS_INSTALL)) {
            $list = (Plugin::$container['hook'] ?? [])[$point] ?? [];
            foreach ($list as $item) {
                if (!is_dir(BASE_PATH . "/app/Plugin/{$item['pluginName']}")) continue;
                if (!class_exists($item['namespace'])) continue;
                Plugin::$currentPluginName = $item['pluginName'];
                $instance = new $item['namespace'];
                Di::inst()->inject($instance);
                $result = call_user_func_array([$instance, $item['method']], $args);
                if ($result) {
                    return $result;
                }
            }
        }
    }

    /**
     * @param int $point
     * @return int
     */
    public static function getHookNum(int $point): int
    {
        return (int)count((array)self::$container[$point]);
    }
}