<?php
declare(strict_types=1);

namespace App\Service\Impl;


use App\Service\App;
use App\Util\File;
use App\Util\Zip;
use GuzzleHttp\Client;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;
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
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Kernel\Exception\JSONException
     */
    private function post(string $uri, array $data = []): mixed
    {
        try {
            $response = $this->client->post(self::APP_URL . $uri, [
                "form_params" => $data
            ]);
            $res = (array)json_decode((string)$response->getBody()->getContents(), true);

        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            throw new JSONException("应用商店请求错误");
        }

        if ($res['code'] != 200) {
            throw new JSONException($res['msg']);
        }

        return $res['data'];
    }

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException|\GuzzleHttp\Exception\GuzzleException
     */
    public function getVersions(): array
    {
        return (array)$this->post("/open/project/version", ["key" => "faka"]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Kernel\Exception\JSONException
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
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Kernel\Exception\JSONException
     */
    public function ad(): array
    {
        return (array)$this->post("/open/project/ad", ["key" => "faka"]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Kernel\Exception\JSONException
     */
    public function install(): void
    {
        $this->post("/open/project/install", ["key" => "faka"]);
    }
}