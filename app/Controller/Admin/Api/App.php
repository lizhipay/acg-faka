<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\ManageLog;
use App\Util\Helper;
use App\Util\Opcache;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class App extends Manage
{
    #[Inject]
    private \App\Service\App $app;

    /**
     * @return array
     */
    public function versions(): array
    {
        return $this->json(200, "ok", $this->app->getVersions());
    }

    /**
     * @return array
     */
    public function latest(): array
    {
        $versions = $this->app->getVersions();
        $latestVersion = $versions[0]['version'];
        $local = config("app")['version'];
        $latest = $latestVersion == $local;
        return $this->json(200, 'ok', ["local" => $local, "latest" => $latest, "version" => $latestVersion]);
    }

    /**
     * @return array
     */
    public function update(): array
    {
        $this->app->update();
        return $this->json(200, "升级完成");
    }

    /**
     * @return array
     */
    public function ad(): array
    {
        return $this->json(200, "ok", $this->app->ad());
    }


    /**
     * @throws JSONException
     */
    public function init(): array
    {
        $config = (array)config("store");
        if (!$config['app_key'] || !$config["app_id"]) {
            throw new JSONException("未登录");
        }
        return $this->json(200, "ok");
    }

    /**
     * @return array
     */
    public function captcha(): array
    {
        $type = (string)$_GET['type'];
        $captcha = $this->app->captcha($type);
        return $this->json(200, "ok", $captcha);
    }

    /**
     * @throws JSONException
     */
    public function register(): array
    {
        if (!$_POST['username'] || !$_POST['password'] || !$_POST['captcha'] || !$_POST['cookie']) {
            throw new JSONException("所有选项都不能为空");
        }
        $register = $this->app->register((string)$_POST['username'], (string)$_POST['password'], (string)$_POST['captcha'], (array)$_POST['cookie']);
        setConfig([
            "app_id" => $register["id"],
            "app_key" => $register["key"],
        ], BASE_PATH . "/config/store.php");
        Opcache::invalidate(BASE_PATH . "/config/store.php");
        return $this->json(200, "success");
    }

    /**
     * @throws JSONException
     */
    public function login(): array
    {
        if (!$_POST['username'] || !$_POST['password']) {
            throw new JSONException("所有选项都不能为空");
        }
        $login = $this->app->login($_POST['username'], $_POST['password']);
        setConfig([
            "app_id" => $login["id"],
            "app_key" => $login["key"],
        ], BASE_PATH . "/config/store.php");
        Opcache::invalidate(BASE_PATH . "/config/store.php");
        return $this->json(200, "success");
    }

    /**
     * @return array
     */
    public function plugins(): array
    {
        $type = -1;
        if (isset($_POST['type'])) {
            $type = (int)$_POST['type'];
        }
        $keywords = (string)$_POST['keywords'];

        $data = [
            "type" => $type,
            "page" => (int)$_POST['page'],
            "limit" => (int)$_POST['limit'],
            "group" => (int)$_POST['group']
        ];

        if ($keywords) {
            $data['keywords'] = urldecode($keywords);
        }

        $plugins = $this->app->plugins($data);

        //判断自己是否安装
        $fileInit = false;
        foreach ($plugins['rows'] as $index => $plugin) {
            if ($plugin['type'] == 0) {
                $installPath = BASE_PATH . "/app/Plugin/{$plugin['plugin_key']}";
                $fileInit = file_exists($installPath . "/Config/Info.php");
                if (is_dir($installPath) && $fileInit) {
                    $config = require($installPath . "/Config/Info.php");
                    $plugins['rows'][$index]['local_version'] = $config[\App\Consts\Plugin::VERSION];
                }
            } else if ($plugin['type'] == 1) {
                $installPath = BASE_PATH . "/app/Pay/{$plugin['plugin_key']}";
                $fileInit = file_exists($installPath . "/Config/Info.php");
                if (is_dir($installPath) && $fileInit) {
                    $config = require($installPath . "/Config/Info.php");
                    $plugins['rows'][$index]['local_version'] = $config["version"];
                }
            } elseif ($plugin['type'] == 2) {
                $installPath = BASE_PATH . "/app/View/User/Theme/{$plugin['plugin_key']}";
                $fileInit = file_exists($installPath . "/Config.php");
                if (is_dir($installPath) && $fileInit) {
                    $config = require($installPath . "/Config.php");
                    $namespace = "App\\View\\User\\Theme\\{$plugin['plugin_key']}\\Config";
                    $plugins['rows'][$index]['local_version'] = $namespace::INFO["VERSION"];
                }
            } else {
                continue;
            }
            if (is_dir($installPath) && $fileInit) {
                $plugins['rows'][$index]['install'] = 1;
            } else {
                $plugins['rows'][$index]['install'] = 0;
            }
        }
        $json = $this->json(200, null, $plugins['rows']);
        $json['count'] = $plugins['count'];
        $json['user'] = $plugins['user'];
        $json['purchase'] = $plugins['purchase'];
        return $json;
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function getUpdates(): array
    {
        $file = BASE_PATH . "/runtime/plugin/store.cache";

        $filectime = filectime($file);
        if ($filectime + 120 > time()) {
            throw new JSONException("CACHE HIT");
        }

        $plugins = $this->app->plugins([
            "type" => -1,
            "page" => 1,
            "limit" => 1000,
            "group" => 0,
        ]);

        //appStroe缓存
        $appStore = (array)json_decode((string)file_get_contents($file), true);

        foreach ($plugins['rows'] as $plugin) {
            // $info = Helper::isInstall($plugin['plugin_key'], (int)$plugin['type']);

            /*     if (!$info) {
                     continue;
                 }*/
            $appStore[$plugin['plugin_key']] = [
                "icon" => $plugin['icon'],
                "name" => $plugin['plugin_name'],
                "version" => $plugin['version'],
                "update_content" => $plugin['update_content'],
                "id" => $plugin['id'],
                "type" => $plugin['type']
            ];
        }

        file_put_contents($file, json_encode($appStore));
        return $this->json(200, "ok", $appStore);
    }

    /**
     * @return array
     */
    public function delUpdates(): array
    {
        $file = BASE_PATH . "/runtime/plugin/store.cache";
        unlink($file);
        return $this->json(200, "ok");
    }

    /**
     * @return array
     */
    public function purchase(): array
    {
        $purchase = $this->app->purchase((int)$_POST['type'], (int)$_POST['plugin_id'], (int)$_POST['payType']);
        return $this->json(200, "下单成功", $purchase);
    }

    /**
     * @return array
     */
    public function install(): array
    {
        $this->app->installPlugin((string)$_POST['plugin_key'], (int)$_POST['type'], (int)$_POST['plugin_id']);
        ManageLog::log($this->getManage(), "安装了应用({$_POST['plugin_key']})");
        return $this->json(200, "安装完成");
    }

    /**
     * @return array
     */
    public function upgrade(): array
    {
        $this->app->updatePlugin((string)$_POST['plugin_key'], (int)$_POST['type'], (int)$_POST['plugin_id']);
        ManageLog::log($this->getManage(), "更新了应用({$_POST['plugin_key']})");
        return $this->json(200, "更新完成");
    }

    /**
     * @return array
     * @throws \ReflectionException
     */
    public function uninstall(): array
    {
        //卸载插件
        $pluginKey = (string)$_POST['plugin_key'];
        $type = (int)$_POST['type'];

        if ($type == 0) {
            \Kernel\Util\Plugin::runHookState($pluginKey, \Kernel\Annotation\Plugin::UNINSTALL);
        }

        $this->app->uninstallPlugin($pluginKey, $type);

        ManageLog::log($this->getManage(), "卸载了应用({$pluginKey})");
        return $this->json(200, "卸载完成");
    }

    /**
     * 开发者插件
     * @return array
     */
    public function developerPlugins(): array
    {
        $plugins = $this->app->developerPlugins([
            "page" => (int)$_POST['page'],
            "limit" => (int)$_POST['limit']
        ]);
        $json = $this->json(200, null, $plugins['rows']);
        $json['count'] = $plugins['count'];
        $json['user'] = $plugins['user'];
        return $json;
    }


    /**
     * 创建插件
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function developerCreatePlugin(): array
    {
        $file = $_POST['icon'];
        if (!file_exists(BASE_PATH . $file)) {
            throw new JSONException("请上传图标");
        }
        $iconBody = file_get_contents(BASE_PATH . $file);
        $_POST['icon'] = $iconBody;
        return $this->json(200, "创建成功", $this->app->developerCreatePlugin($_POST));
    }

    /**
     * @throws \Kernel\Exception\JSONException
     */
    public function developerCreateKit(): array
    {
        $file = $_POST['resource'];
        if (!file_exists(BASE_PATH . $file)) {
            throw new JSONException("请重新上传插件包");
        }
        //上传安装包
        $upload = $this->app->upload([
            [
                'name' => 'file',
                'contents' => fopen(BASE_PATH . $file, 'r'),
                'filename' => 'file.zip'
            ]
        ]);
        //删除本地安装包
        unlink(BASE_PATH . $file);
        //需要审核的安装包临时存放地址
        $_POST['resource'] = $upload['path'];
        return $this->json(200, "提交成功", $this->app->developerCreateKit($_POST));
    }

    /**
     * @return array
     */
    public function developerDeletePlugin(): array
    {
        return $this->json(200, "删除成功", $this->app->developerDeletePlugin($_POST));
    }

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function developerUpdatePlugin(): array
    {
        $file = $_POST['audit_resource'];
        if (!file_exists(BASE_PATH . $file)) {
            throw new JSONException("请重新上传插件包");
        }
        //上传更新包
        $upload = $this->app->upload([
            [
                'name' => 'file',
                'contents' => fopen(BASE_PATH . $file, 'r'),
                'filename' => 'file.zip'
            ]
        ]);
        //删除本地更新包
        unlink(BASE_PATH . $file);
        //需要审核的安装包临时存放地址
        $_POST['audit_resource'] = $upload['path'];
        return $this->json(200, "提交成功", $this->app->developerUpdatePlugin($_POST));
    }

    /**
     * @return array
     */
    public function developerPluginPriceSet(): array
    {
        return $this->json(200, "新的定价已生效", $this->app->developerPluginPriceSet($_POST));
    }


    /**
     * @return array
     */
    public function purchaseRecords(): array
    {
        return $this->json(200, "ok", $this->app->purchaseRecords((int)$_GET['plugin_id']));
    }

    /**
     * @return array
     */
    public function unbind(): array
    {
        $this->app->unbind((int)$_POST['auth_id']);
        return $this->json(200, "解绑成功");
    }

    /**
     * @throws JSONException
     */
    public function setServer(): array
    {
        $server = (int)$_POST['server'];
        $config = config("store");
        $config['server'] = $server;
        $path = BASE_PATH . "/config/store.php";
        setConfig($config, $path);
        Opcache::invalidate($path);
        return $this->json(200, "线路切换成功");
    }
}