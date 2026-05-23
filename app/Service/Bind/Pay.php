<?php
declare(strict_types=1);

namespace App\Service\Bind;


use App\Util\Opcache;

/**
 * Class PayService
 * @package App\Service\Impl
 */
class Pay implements \App\Service\Pay
{

    /**
     * @param string $handle
     * @return string
     */
    public function getPluginLog(string $handle): string
    {
        $path = BASE_PATH . "/app/Pay/{$handle}/runtime.log";
        return (string)file_get_contents($path);
    }

    /**
     * @param string $handle
     * @return bool
     */
    public function ClearPluginLog(string $handle): bool
    {
        $path = BASE_PATH . "/app/Pay/{$handle}/runtime.log";
        return unlink($path);
    }

    /**
     * @return array
     */
    public function getPlugins(): array
    {
        $path = BASE_PATH . '/app/Pay/';
        $list = scandir($path);
        $dir = [];
        foreach ($list as $item) {
            if ($item != '.' && $item != '..' && is_dir($path . $item)) {
                $dir[] = $item;
            }
        }
        //插件列表
        $plug = [];
        foreach ($dir as $value) {
            $platformInfo = $this->getPluginInfo($value);
            if (!empty($platformInfo)) {
                $plug[] = $platformInfo;
            }
        }
        return $plug;
    }

    /**
     * @param string $name
     * @return array
     */
    public function getPluginInfo(string $name): array
    {
        $plugPath = BASE_PATH . '/app/Pay/' . $name;
        //判断插件信息是否存在
        if (file_exists($plugPath . '/Config/Info.php')) {
            $infoPath = $plugPath . '/Config/Info.php';
            $submitPath = $plugPath . '/Config/Submit.php';
            $submitJsPath = $plugPath . '/Config/Submit.js';
            $configPath = $plugPath . '/Config/Config.php';

            Opcache::invalidate($infoPath, $submitPath, $configPath);

            //解析信息
            $info = require($infoPath);
            $submit = file_exists($submitPath) ? require($submitPath) : [];
            $config = file_exists($configPath) ? require($configPath) : [];

            if (file_exists($submitJsPath)) {
                $submit = file_get_contents($submitJsPath);
            }

            if (is_array($submit)) {
                foreach ($submit as $index => $item) {
                    if (isset($config[$item['name']])) {
                        $submit[$index]['default'] = $config[$item['name']];
                    }
                }
            }

            return [
                'id' => $name,
                'handle' => $name,
                'info' => $info,
                'submit' => $submit,
                'config' => $config
            ];
        }
        return [];
    }
}