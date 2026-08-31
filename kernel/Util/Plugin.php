<?php
declare(strict_types=1);

namespace Kernel\Util;


use App\Util\Opcache;
use Kernel\Consts\Base;
use Kernel\Container\Di;
use Kernel\Plugin\Entity\Stock;

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
        $submitJsPath = $path . '/Config/Submit.js';
        $configPath = $path . '/Config/Config.php';
        if (!file_exists($infoPath)) {
            return null;
        }

        if (!$cache) {
            Opcache::invalidate($infoPath, $submitPath, $configPath);
        }

        $info = (array)require($infoPath);
        $submit = file_exists($submitPath) ? require($submitPath) : [];
        $config = file_exists($configPath) ? require($configPath) : [];

        if (file_exists($submitJsPath)) {
            $submit = file_get_contents($submitJsPath);
        }

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
                                $obj = new $namespace();
                                Di::inst()->inject($obj);
                                call_user_func_array([$obj, $method->getName()], $args);
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
     * @return array|Stock|string|bool|void
     * @throws \ReflectionException
     */
    public static function hook(int $point, mixed &...$args)
    {
        if (Context::get(Base::STORE_STATUS) && Context::get(Base::IS_INSTALL)) {
            $list = (Plugin::$container['hook'] ?? [])[$point] ?? [];
            $results = "";

            foreach ($list as $item) {
                if (!is_dir(BASE_PATH . "/app/Plugin/{$item['pluginName']}")) continue;
                if (!class_exists($item['namespace'])) continue;
                Plugin::$currentPluginName = $item['pluginName'];
                $instance = new $item['namespace'];
                Di::inst()->inject($instance);
                $result = call_user_func_array([$instance, $item['method']], $args);

                // Stock 是短路信号，必须最先判
                if ($result instanceof Stock) {
                    return $result;
                }

                // bool 同样是决策信号（如 SERVICE_SMTP_SEND_BEFORE 返回 true 表示已由插件接管），
                // 之前会被静默丢弃导致该契约从未生效，这里与 Stock 同级短路返回。
                if (is_bool($result)) {
                    return $result;
                }

                // 绝大多数钩子是 void，这里直接跳过，省得往下走一圈
                if ($result === null) {
                    continue;
                }

                if (is_string($result) && !is_array($results)) {
                    $results .= $result;
                    continue;
                }

                // 数组和**对象**都进收集模式。
                //
                // 对象原来是被静默丢弃的，于是想让订阅方 return 一个实体
                // （比如 ThreadManager 的 Task 描述符）就只能退而求其次，
                // 改用 `array &$tasks` 引用修改 —— API 难看，而且忘了写引用符号
                // 也不会报错，只会让注册神秘失败。现在对象一视同仁地收集，
                // 订阅方可以直接 `return new XxxEntity(...)`。
                //
                // 顺带把 string 也纳入收集：某个钩子点如果既有插件返回数组/对象、
                // 又有插件返回字符串，老代码会走到 `$results .= $result`，
                // 而那时 $results 已经是数组 —— PHP 8 下直接 TypeError 把请求打挂。
                // 与其崩，不如收进同一个列表里。
                if (is_array($result) || is_object($result) || is_string($result)) {
                    if (!is_array($results)) {
                        $results = $results === "" ? [] : [$results];
                    }
                    $results[] = $result;
                }
            }

            return $results;
        }
    }

    /**
     * @param int $point
     * @return int
     */
    public static function getHookNum(int $point): int
    {
        return (int)count((array)self::$container['hook'][$point]);
    }
}