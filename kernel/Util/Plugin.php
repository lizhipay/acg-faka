<?php
declare(strict_types=1);

namespace Kernel\Util;


class Plugin
{
    /**
     * @var array
     */
    private static array $container = [];

    /**
     * @var string|null
     */
    public static ?string $currentPluginName = null;

    /**
     * @param string $name
     * @return array|null
     */
    public static function getPlugin(string $name): ?array
    {
        $path = BASE_PATH . "/app/Plugin/{$name}";

        $infoPath = $path . '/Config/Info.php';
        $submitPath = $path . '/Config/Submit.php';
        $configPath = $path . '/Config/Config.php';
        if (!file_exists($infoPath) || !file_exists($submitPath) || !file_exists($configPath)) {
            return null;
        }
        $info = (array)require($infoPath);
        $submit = (array)require($submitPath);
        $config = (array)require($configPath);

        $submit[] = [
            "title" => "插件状态",
            "name" => \App\Consts\Plugin::STATUS,
            "type" => "switch",
            "text" => "启用"
        ];

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
     * @return array|null
     */
    public static function getPlugins(): ?array
    {
        $path = BASE_PATH . "/app/Plugin/";
        $scan = File::scan($path);
        $plugins = [];
        foreach ($scan as $item) {
            $plugin = self::getPlugin($item);
            if ($plugin) {
                $plugin[\App\Consts\Plugin::PLUGIN_NAME] = $item;
                $plugins[] = $plugin;
            }
        }
        return $plugins;
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public static function scan(): void
    {
        $plugins = self::getPlugins();
        $path = BASE_PATH . "/app/Plugin/";
        foreach ($plugins as $plugin) {
            $pluginName = $plugin[\App\Consts\Plugin::PLUGIN_NAME];
            if ($plugin[\App\Consts\Plugin::PLUGIN_CONFIG][\App\Consts\Plugin::STATUS]) {
                //扫描插件的hook
                $hookScan = File::scan($path . $pluginName . "/Hook/", true);
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
                                if (isset($arguments['point'])) {
                                    Plugin::$container[$arguments['point']][] = ["namespace" => $namespace, "method" => $method->getName(), "pluginName" => $pluginName];
                                }
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
     * @return false|mixed
     */
    public static function hook(int $point, mixed ...$args)
    {
        $list = self::$container[$point];
        foreach ($list as $item) {
            \Kernel\Util\Plugin::$currentPluginName = $item['pluginName'];
            return call_user_func_array([new $item['namespace'], $item['method']], $args);
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