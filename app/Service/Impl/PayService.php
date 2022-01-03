<?php
declare(strict_types=1);

namespace App\Service\Impl;


use App\Service\Pay;
use App\Util\Opcache;

/**
 * Class PayService
 * @package App\Service\Impl
 */
class PayService implements Pay
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
        if (file_exists($plugPath . '/Config/Info.php') && file_exists($plugPath . '/Config/Submit.php')) {
            $infoPath = $plugPath . '/Config/Info.php';
            $submitPath = $plugPath . '/Config/Submit.php';
            $configPath = $plugPath . '/Config/Config.php';

            Opcache::invalidate($infoPath, $submitPath, $configPath);

            //解析信息
            $info = require($infoPath);
            $submit = require($submitPath);
            $config = require($configPath);

            foreach ($submit as $index => $item) {
                if (isset($config[$item['name']])) {
                    $submit[$index]['default'] = $config[$item['name']];
                }
            }
            return [
                'id' => $name,
                'handle' => $name,
                'info' => $info,
                'submit' => $submit
            ];
        }
        return [];
    }
}