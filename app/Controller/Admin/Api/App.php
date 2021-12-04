<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
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
        $plugins = $this->app->plugins([
            "type" => $type,
            "page" => (int)$_POST['page'],
            "limit" => (int)$_POST['limit'],
            "group" => (int)$_POST['group'],
        ]);
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
        return $this->json(200, "安装完成");
    }

    /**
     * @return array
     */
    public function upgrade(): array
    {
        $this->app->updatePlugin((string)$_POST['plugin_key'], (int)$_POST['type'], (int)$_POST['plugin_id']);
        return $this->json(200, "更新完成");
    }

    /**
     * @return array
     */
    public function uninstall(): array
    {
        //安装插件
        $this->app->uninstallPlugin((string)$_POST['plugin_key'], (int)$_POST['type']);
        return $this->json(200, "卸载完成");
    }
}