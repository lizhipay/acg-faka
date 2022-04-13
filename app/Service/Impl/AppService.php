<?php
declare(strict_types=1);

namespace App\Service\Impl;


use App\Service\App;
use App\Util\File;
use App\Util\Str;
use App\Util\Zip;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Kernel\Annotation\Inject;
use Kernel\Consts\Base;
use Kernel\Exception\JSONException;
use Kernel\Util\Context;
use Kernel\Util\SQL;

/**
 * Class AppService
 * @package App\Service\Impl
 */
class AppService implements App
{

    #[Inject]
    private Client $client;

    /**
     * @param string $uri
     * @param array $data
     * @param array|null $cookie
     * @return mixed
     * @throws JSONException
     */
    private function post(string $uri, array $data = [], ?array &$cookies = null): mixed
    {
        try {
            $form = [
                "form_params" => $data,
                "verify" => false
            ];
            if (is_array($cookies)) {
                $form["cookies"] = CookieJar::fromArray([
                    "GOLANG_ID" => $cookies['GOLANG_ID']
                ], parse_url(self::APP_URL)['host']);
            }
            $response = $this->client->post(self::APP_URL . $uri, $form);
            if ($cookies !== null) {
                $cookie = implode(";", (array)$response->getHeader("Set-Cookie"));
                $explode = explode(";", $cookie);
                $cookies = [];
                foreach ($explode as $item) {
                    $it = explode("=", $item);
                    $cookies[trim((string)$it[0])] = trim((string)$it[1]);
                }
            }
            $res = (array)json_decode((string)$response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new JSONException("应用商店请求错误");
        }
        if ($res['code'] != 200) {
            throw new JSONException($res['msg']);
        }

        return $res['data'];
    }

    /**
     * @param string $uri
     * @param array $data
     * @return mixed
     * @throws GuzzleException
     * @throws JSONException
     */
    private function storeRequest(string $uri, array $data = []): mixed
    {
        $store = config("store");
        $data['sign'] = Str::generateSignature($data, (string)$store["app_key"]);
        $response = $this->client->post(self::APP_URL . $uri, [
            "form_params" => $data,
            "headers" => ["appId" => (int)$store['app_id'], "appKey" => Context::get(Base::LOCK)],
            "verify" => false
        ]);
        $res = (array)json_decode((string)$response->getBody()->getContents(), true);

        if ($res['code'] != 200) {
            throw new JSONException($res['msg']);
        }
        return $res['data'];
    }

    /**
     * @param string $uri
     * @param array $data
     * @return array|null
     * @throws GuzzleException
     */
    private function storeDownload(string $uri, array $data = []): ?string
    {
        $store = config("store");
        $data['sign'] = Str::generateSignature($data, (string)$store["app_key"]);

        $path = BASE_PATH . "/kernel/Install/OS/";
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $fileName = md5((string)time()) . ".zip";
        $fileHandle = fopen($path . $fileName, "w+");
        $response = $this->client->post(self::APP_URL . $uri, [
            "form_params" => $data,
            "verify" => false,
            "headers" => ["appId" => (int)$store['app_id'], "appKey" => Context::get(Base::LOCK)],
            RequestOptions::SINK => $fileHandle
        ]);

        if ($response->getStatusCode() === 200) {
            return $fileName;
        }

        return null;
    }

    /**
     * @return array
     * @throws JSONException|GuzzleException
     */
    public function getVersions(): array
    {
        if (Context::get(Base::LOCK) == "") {
            file_put_contents(BASE_PATH . "/kernel/Install/Lock", Str::generateRandStr(32));
        }
        return (array)$this->post("/open/project/version", ["key" => "faka"]);
    }

    /**
     * @param string $key
     * @param int $type 插件类型
     * @param int $pluginId
     * @throws GuzzleException
     * @throws JSONException
     * @throws \ReflectionException
     */
    public function installPlugin(string $key, int $type, int $pluginId): void
    {
        //默认位置，通用插件
        $pluginPath = BASE_PATH . "/app/Plugin/{$key}/";
        $fileInit = file_exists($pluginPath . "/Config/Info.php");
        if ($type == 1) {
            //支付插件
            $pluginPath = BASE_PATH . "/app/Pay/{$key}/";
            $fileInit = file_exists($pluginPath . "/Config/Info.php");
        } elseif ($type == 2) {
            //网站模板
            $pluginPath = BASE_PATH . "/app/View/User/Theme/{$key}/";
            $fileInit = file_exists($pluginPath . "/Config.php");
        }

        if (!is_dir($pluginPath)) {
            mkdir($pluginPath, 0777, true);
        }

        if ($fileInit) {
            throw new JSONException("该插件已被安装，请勿重复安装");
        }

        $storeDownload = $this->storeDownload("/store/install", [
            "plugin_id" => $pluginId
        ]);
        if (!$storeDownload) {
            throw new JSONException("安装失败，请联系技术人员");
        }
        //下载完成，开始安装
        $src = BASE_PATH . "/kernel/Install/OS/{$storeDownload}";
        if (!Zip::unzip($src, $pluginPath)) {
            throw new JSONException("安装失败，请检查是否有写入权限");
        }
        //安装完成，删除src
        unlink($src);
        //判断目标目录是否有install.sqll
        $installSql = $pluginPath . "install.sql";
        if (file_exists($installSql)) {
            $database = config("database");
            SQL::import($installSql, $database['host'], $database['database'], $database['username'], $database['password'], $database['prefix']);
        }

        if ($type == 0) {
            //安装
            \Kernel\Util\Plugin::runHookState($key, \Kernel\Annotation\Plugin::INSTALL);
        }
    }

    /**
     * @param string $key
     * @param int $type
     * @param int $pluginId
     * @throws GuzzleException
     * @throws JSONException
     * @throws \ReflectionException
     */
    public function updatePlugin(string $key, int $type, int $pluginId): void
    {
        //默认位置，通用插件
        $pluginPath = BASE_PATH . "/app/Plugin/{$key}/";
        if ($type == 1) {
            //支付插件
            $pluginPath = BASE_PATH . "/app/Pay/{$key}/";
        } elseif ($type == 2) {
            //网站模板
            $pluginPath = BASE_PATH . "/app/View/User/Theme/{$key}/";
        }
        if (!is_dir($pluginPath)) {
            throw new JSONException("该插件还未安装，请先安装插件后再进行更新");
        }
        $storeDownload = $this->storeDownload("/store/update", [
            "plugin_id" => $pluginId
        ]);
        if (!$storeDownload) {
            throw new JSONException("更新失败，请联系技术人员");
        }
        //下载完成，开始安装
        $src = BASE_PATH . "/kernel/Install/OS/{$storeDownload}";
        if (!Zip::unzip($src, $pluginPath)) {
            throw new JSONException("更新失败，请检查是否有写入权限");
        }
        //更新完成，删除src
        unlink($src);
        //判断目标目录是否有update.sql
        $updateSql = $pluginPath . "update.sql";
        if (file_exists($updateSql)) {
            $database = config("database");
            SQL::import($updateSql, $database['host'], $database['database'], $database['username'], $database['password'], $database['prefix']);
        }

        if ($type == 0) {
            \Kernel\Util\Plugin::runHookState($key, \Kernel\Annotation\Plugin::UPGRADE);
        }
    }

    /**
     * 卸载
     * @param string $key
     * @param int $type
     */
    public function uninstallPlugin(string $key, int $type): void
    {
        //默认位置，通用插件
        $pluginPath = BASE_PATH . "/app/Plugin/{$key}/";
        if ($type == 1) {
            //支付插件
            $pluginPath = BASE_PATH . "/app/Pay/{$key}/";
        } elseif ($type == 2) {
            //网站模板
            $pluginPath = BASE_PATH . "/app/View/User/Theme/{$key}/";
        }
        if (is_dir($pluginPath)) {
            //开始卸载
            File::delDirectory($pluginPath);
        }
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Kernel\Exception\JSONException
     */
    public function purchaseRecords(int $pluginId): array
    {
        return $this->storeRequest("/store/records", [
            "plugin_id" => $pluginId
        ]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Kernel\Exception\JSONException
     */
    public function unbind(int $authId): array
    {
        return $this->storeRequest("/store/unbind", [
            "auth_id" => $authId
        ]);
    }

    /**
     * @throws GuzzleException
     * @throws JSONException
     */
    public function update(): void
    {
        $versions = $this->getVersions();
        $latestVersion = $versions[0]['version'];
        $localVersion = config("app")['version'];
        if ($latestVersion == $localVersion) {
            throw new JSONException("你已经是最新版本了");
        }

        $vrs = array_reverse($versions);
        $startVersion = 0;

        foreach ($vrs as $index => $vr) {
            if ($vr['version'] == $localVersion) {
                $startVersion = $index;
                break;
            }
        }

        foreach ($vrs as $key => $val) {
            if ($startVersion < $key) {
                //下载更新包
                $updateContent = file_get_contents($val['update_url']);

                if (!$updateContent) {
                    throw new JSONException("更新包下载失败");
                }
                //下载完成，写入到本地缓存目录
                $zipPath = BASE_PATH . '/kernel/Install/Update/' . $val['version'];

                if (!is_dir($zipPath)) {
                    mkdir($zipPath, 0777, true);
                }

                if (!file_put_contents($zipPath . '/update.zip', $updateContent)) {
                    throw new JSONException("更新包写入失败，没有文件写入权限");
                }

                if (!Zip::unzip($zipPath . '/update.zip', $zipPath)) {
                    throw new JSONException("ZIP解压缩失败，请检查程序是否有写入权限！");
                }

                //升级数据库
                $sql = $zipPath . '/update.sql';

                if (file_exists($sql)) {
                    //导入数据库
                    $database = config("database");
                    SQL::import($sql, $database['host'], $database['database'], $database['username'], $database['password'], $database['prefix']);
                }

                //升级程序，防止sql等命令错误，通过php代码来执行sql，新增时间：2022/04/07
                $ext = $zipPath . '/update.php';
                if (file_exists($ext)) {
                    require($ext);
                    if (!class_exists("\Update")) {
                        throw new JSONException("更新主类未装载成功，请重试");
                    }
                    $updateObj = new \Update();
                    if (!method_exists($updateObj, "exec")) {
                        throw new JSONException("更新子程序不存在，请重试");
                    }
                    $updateObj->exec();
                }

                //升级程序
                try {
                    File::copyDirectory($zipPath . '/file', BASE_PATH);
                } catch (\Exception $e) {
                    throw new JSONException("程序升级失败，没有写入目录权限！");
                }

                //升级完成，记录版本号
                setConfig(["version" => $val["version"]], BASE_PATH . "/config/app.php");
            }
        }
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function ad(): array
    {
        return (array)$this->post("/open/project/ad", ["key" => "faka"]);
    }

    /**
     * @throws JSONException
     */
    public function install(): void
    {
        $this->post("/open/project/install", ["key" => "faka"]);
    }

    /**
     * @param string $type
     * @return array
     * @throws JSONException
     */
    public function captcha(string $type): array
    {
        $cookie = [];
        $result = (array)$this->post("/auth/captcha", [
            "type" => $type
        ], $cookie);
        $result["cookie"] = $cookie;
        return $result;
    }

    /**
     * @param string $username
     * @param string $password
     * @param string $captcha
     * @param string $cookie
     * @return array
     * @throws JSONException
     */
    public function register(string $username, string $password, string $captcha, array $cookie): array
    {
        return (array)$this->post("/auth/register", [
            "captcha" => $captcha,
            "username" => $username,
            "password" => $password
        ], $cookie);
    }

    /**
     * @throws JSONException
     */
    public function login(string $username, string $password): array
    {
        return (array)$this->post("/auth/login", [
            "username" => $username,
            "password" => $password
        ]);
    }

    /**
     * @throws GuzzleException
     * @throws JSONException
     */
    public function plugins(array $data): array
    {
        return $this->storeRequest("/store/plugins", $data);
    }

    /**
     * @param array $data
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Kernel\Exception\JSONException
     */
    public function developerPlugins(array $data): array
    {
        return $this->storeRequest("/developer/plugins", $data);
    }


    /**
     * @param array $data
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Kernel\Exception\JSONException
     */
    public function developerCreatePlugin(array $data): array
    {
        return $this->storeRequest("/developer/create", $data);
    }

    /**
     * @throws GuzzleException
     * @throws JSONException
     */
    public function purchase(int $type, int $pluginId, int $payType): array
    {
        return $this->storeRequest("/store/purchase", [
            "type" => $type,
            "payType" => $payType,
            "plugin_id" => $pluginId,
            "return" => \App\Util\Client::getUrl() . "/admin/store/home"
        ]);
    }

    /**
     * @param array $data
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Kernel\Exception\JSONException
     */
    public function developerCreateKit(array $data): array
    {
        return $this->storeRequest("/developer/createKit", $data);
    }


    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Kernel\Exception\JSONException
     */
    public function developerDeletePlugin(array $data): array
    {
        return $this->storeRequest("/developer/deletePlugin", $data);
    }

    /**
     * @param array $data
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function upload(array $data): array
    {
        try {
            $form = [
                "multipart" => $data,
                "verify" => false
            ];
            $response = $this->client->post(self::APP_URL . "/open/project/upload", $form);
            $res = (array)json_decode((string)$response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new JSONException("应用商店连接失败");
        }
        if ($res['code'] != 200) {
            throw new JSONException($res['msg']);
        }
        return $res['data'];
    }

    /**
     * @param array $data
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Kernel\Exception\JSONException
     */
    public function developerUpdatePlugin(array $data): array
    {
        return $this->storeRequest("/developer/createUpdate", $data);
    }

    /**
     * @param array $data
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Kernel\Exception\JSONException
     */
    public function developerPluginPriceSet(array $data): array
    {
        return $this->storeRequest("/developer/priceSet", $data);
    }
}