<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Consts\Hook;
use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Util\Opcache;
use App\Util\Theme;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\Post;
use Kernel\Exception\JSONException;

#[Interceptor([ManageSession::class], Interceptor::TYPE_API)]
class Plugin extends Manage
{
    /**
     * @return array
     */
    public function getPlugins(): array
    {
        $plugins = \Kernel\Util\Plugin::getPlugins(\Kernel\Util\Plugin::isCache());
        $appStore = (array)json_decode((string)file_get_contents(BASE_PATH . "/runtime/plugin/store.cache"), true);
        $path = BASE_PATH . "/app/Plugin/";

        $keywords = urldecode((string)$_POST['keywords']);
        $status = $_POST['status'];

        foreach ($plugins as $key => $plugin) {
            $plugins[$key]["id"] = $plugin[\App\Consts\Plugin::PLUGIN_NAME];
            if (!array_key_exists($plugins[$key]["id"], $appStore)) {
                $plugins[$key]['icon'] = "/favicon.ico";
            } else {
                $plugins[$key]['icon'] = \App\Service\App::APP_URL . $appStore[$plugins[$key]["id"]]['icon'];
            }
            //判断文档是否存在
            if (file_exists($path . $plugins[$key]["id"] . "/Wiki/Index.html")) {
                $plugins[$key]['wiki'] = "/app/Plugin/{$plugins[$key]["id"]}/Wiki/Index.html";
            }

            if ($status !== "" && $status !== null) {
                //未运行
                if ((int)$plugin[\App\Consts\Plugin::PLUGIN_CONFIG]['STATUS'] != $status) {
                    unset($plugins[$key]);
                }
            }

            if ($keywords) {
                if (!str_contains($plugin[\App\Consts\Plugin::NAME], $keywords) && !str_contains($plugin[\App\Consts\Plugin::DESCRIPTION], $keywords)) {
                    unset($plugins[$key]);
                }
            }
        }

        $plugins = array_values($plugins);
        return $this->json(200, 'success', $plugins);
    }

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     * @throws \ReflectionException
     */
    public function setConfig(): array
    {
        $map = $_POST;
        if (!$map['id'] === "" || !isset($map['id'])) {
            throw new JSONException("插件不存在");
        }
        $id = $map['id'];
        unset($map['id']);

        hook(Hook::ADMIN_API_PLUGIN_SAVE_CONFIG, $id, $map);//12/09-重写HOOK逻辑
        \Kernel\Util\Plugin::runHookState($id, \Kernel\Annotation\Plugin::SAVE_CONFIG, $id, $map);//2022/01/12新增插件保存配置逻辑，无需启用插件也可以hook

        //   $map = array_merge($map, (array));
        $plugin = \Kernel\Util\Plugin::getPlugin($id, false);
        if (!$plugin) {
            throw new JSONException("插件不存在");
        }
        $config = $plugin[\App\Consts\Plugin::PLUGIN_CONFIG];

        if ((int)$config['STATUS'] == 0 && (int)$map['STATUS'] == 1) {
            //触发启动时
            \Kernel\Util\Plugin::runHookState($id, \Kernel\Annotation\Plugin::START);
        } else if ((int)$config['STATUS'] == 1 && (int)$map['STATUS'] == 0) {
            //触发停止时
            \Kernel\Util\Plugin::runHookState($id, \Kernel\Annotation\Plugin::STOP);
        }

        foreach ($map as $k => $v) {
            $config[$k] = is_scalar($v) ? urldecode((string)$v) : $v;
        }

        unlink(BASE_PATH . "/runtime/plugin/app.cache");
        setConfig($config, BASE_PATH . '/app/Plugin/' . $id . '/Config/Config.php');

        return $this->json(200, '配置已生效');
    }

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     * @throws \ReflectionException
     */
    public function setThemeConfig(): array
    {
        $map = $_POST;
        if (!$map['id'] === "" || !isset($map['id'])) {
            throw new JSONException("模板不存在");
        }
        $id = $map['id'];
        unset($map['id']);
        $theme = Theme::getConfig($id);
        if (!$theme) {
            throw new JSONException("模板不存在");
        }
        $config = $theme["setting"];
        foreach ($map as $k => $v) {
            $config[$k] = is_scalar($v) ? urldecode((string)$v) : $v;
        }
        setConfig($config, BASE_PATH . "/app/View/User/Theme/{$id}/Setting.php");
        return $this->json(200, '模板设置已生效');
    }

    /**
     * 获取插件日志
     * @param string $handle
     * @return array
     */
    public function getPluginLog(#[Post] string $handle): array
    {
        $pluginLog = \App\Util\Plugin::getPluginLog($handle);
        return $this->json(200, 'success', ['log' => $pluginLog]);
    }

    /**
     * @param string $handle
     * @return array
     */
    public function ClearPluginLog(#[Post] string $handle): array
    {
        \App\Util\Plugin::ClearPluginLog($handle);
        return $this->json(200, 'success');
    }

}