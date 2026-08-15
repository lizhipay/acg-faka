<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\ManageLog;
use App\Util\Opcache;
use App\Util\PayConfig;
use App\Util\PluginPacker;
use App\Util\Theme;
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
        $owner = -1;
        if (isset($_POST['equal-owner'])) {
            $owner = (int)$_POST['equal-owner'];
        }
        $keywords = (string)$_POST['keywords'];

        $data = [
            "owner" => $owner,
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

            $plugins['rows'][$index]['icon'] = \App\Service\App::APP_URL . "/{$plugins['rows'][$index]['icon']}";
        }

        //应用商店条目来自官方远端，属动态文案：只翻展示用的名称与简介，plugin_key 不动
        $plugins['rows'] = \Kernel\Util\Lang::transList($plugins['rows'], ['plugin_name', 'description']);

        $json = $this->json(data: [
            "list" => $plugins['rows'],
            "total" => $plugins['count']
        ]);

        $json['user'] = $plugins['user'];
        $json['purchase'] = $plugins['purchase'];
        return $json;
    }

    /**
     * @return array
     */
    public function getUpdates(): array
    {
        $file = BASE_PATH . "/runtime/plugin/store.cache";
        $update = BASE_PATH . "/runtime/plugin/update.cache";

        $filectime = filectime($file);

        if ($filectime + 120 > time()) {
            $updateData = (array)json_decode((string)file_get_contents($update), true) ?: [];
            return array_merge($this->json(200, "ok"), $updateData);
        }

        $plugins = $this->app->plugins([
            "type" => -1,
            "page" => 1,
            "limit" => 1000,
            "group" => 0,
        ]);

        //appStroe缓存
        $appStore = (array)json_decode((string)file_get_contents($file), true) ?: [];

        $generalPlugin = 0;
        $themePlugin = 0;
        $payPlugin = 0;

        foreach ($plugins['rows'] as $plugin) {
            $appStore[$plugin['plugin_key']] = [
                "icon" => $plugin['icon'],
                "name" => $plugin['plugin_name'],
                "version" => $plugin['version'],
                "update_content" => $plugin['update_content'],
                "id" => $plugin['id'],
                "type" => $plugin['type']  // 0 = 通用插件，2 = 模版 , 1 = 支付插件
            ];

            switch ($plugin['type']) {
                case 0:
                    $plg = \Kernel\Util\Plugin::getPlugin($plugin['plugin_key']);
                    if (!empty($plg)) {
                        if ($plg['VERSION'] !== $plugin['version']) {
                            $generalPlugin++;
                        }
                    }
                    break;
                case 1:
                    $plg = PayConfig::info($plugin['plugin_key']);
                    if (!empty($plg)) {
                        if ($plg['version'] !== $plugin['version']) {
                            $payPlugin++;
                        }
                    }
                    break;
                case 2:
                    $plg = Theme::getConfig($plugin['plugin_key']);
                    if (!empty($plg)) {
                        if ($plg['info']['VERSION'] !== $plugin['version']) {
                            $themePlugin++;
                        }
                    }
                    break;
            }
        }

        $updateData = ['generalPlugin' => $generalPlugin, 'payPlugin' => $payPlugin, 'themePlugin' => $themePlugin];
        file_put_contents($file, json_encode($appStore));
        file_put_contents($update, json_encode($updateData));
        return array_merge($this->json(200, "ok", $appStore), $updateData);
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
     */
    public function uninstall(): array
    {
        //卸载插件
        $pluginKey = (string)$_POST['plugin_key'];
        $type = (int)$_POST['type'];

        if ($type == 0) {
            _plugin_stop($pluginKey);
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

        foreach ($plugins['rows'] as &$plugin) {
            $plugin['icon'] = \App\Service\App::APP_URL . "/{$plugin['icon']}";
        }
        unset($plugin);

        //开发者中心列表同样是远端动态文案
        $plugins['rows'] = \Kernel\Util\Lang::transList($plugins['rows'], ['plugin_name', 'description']);

        $json = $this->json(data: [
            "list" => $plugins['rows'],
            "total" => $plugins['count']
        ]);
        $json['user'] = $plugins['user'];
        return $json;
    }


    /**
     * 创建插件
     * @return array
     * @throws JSONException
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
     * @throws JSONException
     */
    public function developerCreateKit(): array
    {
        $file = (string)($_POST['resource'] ?? '');

        if ($file === '') {
            //没传压缩包 = 走服务端自动打包（开发者中心的默认方式）
            $_POST['resource'] = $this->autoPackage(
                (int)($_POST['id'] ?? 0),
                trim((string)($_POST['version'] ?? '')),
                false
            );
            unset($_POST['version']);
            return $this->json(200, "提交成功", $this->app->developerCreateKit($_POST));
        }

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
        unset($_POST['version']);
        return $this->json(200, "提交成功", $this->app->developerCreateKit($_POST));
    }

    /**
     * 从本机插件目录现打一个包并直传商店，返回商店的临时 path。
     *
     * 开发者原本要自己 zip 好再上传，很容易出事：忘了删 Config.php（把自己的
     * 密钥和启用状态发给所有用户）、把日志一起打进去、把插件文件夹本身也打进去、
     * 或者包内版本号和填的版本号对不上被审核打回。这些现在全部由服务端保证。
     *
     * @param string $version 非空则先写回插件自己的版本文件再打包，保证两者一致
     * @param bool $isUpdate true=更新包（剔除 Config.php），false=安装包（Config.php 清空成 return [];）
     * @throws JSONException
     */
    private function autoPackage(int $pluginId, string $version, bool $isUpdate): string
    {
        $plugin = PluginPacker::resolveFromStore($this->app, $pluginId);
        $type = (int)$plugin['type'];
        $key = (string)$plugin['plugin_key'];
        $dir = PluginPacker::sourceDir($key, $type);

        if ($version !== '') {
            PluginPacker::syncVersion($dir, $type, $version);
        }

        $bytes = PluginPacker::pack($dir, $type, $key, $isUpdate);

        $upload = $this->app->upload([
            [
                'name' => 'file',
                'contents' => $bytes,
                'filename' => 'file.zip'
            ]
        ]);

        $path = (string)($upload['path'] ?? '');
        if ($path === '') {
            throw new JSONException("插件包上传失败，商店未返回路径");
        }
        return $path;
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
     * @throws JSONException
     */
    public function developerUpdatePlugin(): array
    {
        $file = (string)($_POST['audit_resource'] ?? '');

        if ($file === '') {
            //没传压缩包 = 服务端自动打包。版本号同时写回插件的 Info，
            //包内版本与 audit_version 必定一致，不会再被审核以「版本不符」打回
            $_POST['audit_resource'] = $this->autoPackage(
                (int)($_POST['id'] ?? 0),
                trim((string)($_POST['audit_version'] ?? '')),
                true
            );
            return $this->json(200, "提交成功", $this->app->developerUpdatePlugin($_POST));
        }

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
        return $this->json(data: ["list" => $this->app->purchaseRecords((int)$_GET['plugin_id'])]);
    }

    /**
     * @return array
     */
    public function unbind(): array
    {
        $this->app->unbind((int)$_POST['auth_id']);
        return $this->json(200, "绑定授权成功");
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

    /**
     * @return array
     */
    public function levels(): array
    {
        return $this->json(data: ["list" => $this->app->levels()]);
    }

    /**
     * @return array
     */
    public function bindLevel(): array
    {
        $this->app->bindLevel((int)$_POST['auth_id']);
        return $this->json(200, "绑定授权成功");
    }

    /**
     * @return array
     */
    public function service(): array
    {
        return $this->json(data: $this->app->service());
    }


    /**
     * @return array
     */
    public function editPassword(): array
    {
        $this->app->editPassword($_POST);
        return $this->json();
    }
}