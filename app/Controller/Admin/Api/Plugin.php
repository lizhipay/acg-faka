<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class Plugin extends Manage
{
    /**
     * @return array
     */
    public function getPlugins(): array
    {
        $plugins = \Kernel\Util\Plugin::getPlugins();
        foreach ($plugins as $key => $plugin) {
            $plugins[$key]["id"] = $plugin[\App\Consts\Plugin::PLUGIN_NAME];
        }
        return $this->json(200, 'success', $plugins);
    }

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function setConfig(): array
    {
        $map = $_POST;
        if (!$map['id'] === "" || !isset($map['id'])) {
            throw new JSONException("插件不存在");
        }
        $id = $map['id'];
        unset($map['id']);
        $plugin = \Kernel\Util\Plugin::getPlugin($id);
        if (!$plugin) {
            throw new JSONException("插件不存在");
        }
        $config = $plugin[\App\Consts\Plugin::PLUGIN_CONFIG];
        foreach ($map as $k => $v) {
            $config[$k] = urldecode($v);
        }
        setConfig($config, BASE_PATH . '/app/Plugin/' . $id . '/Config/Config.php');
        return $this->json(200, '配置已生效');
    }
}