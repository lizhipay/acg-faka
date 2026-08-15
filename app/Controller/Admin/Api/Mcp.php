<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\ManageLog;
use App\Util\Client;
use App\Util\Date;
use App\Util\Opcache;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

/**
 * MCP 访问秘钥管理（后台会话保护）。
 *
 * 供开发者中心页面读取/重置/开关 MCP 接入秘钥。秘钥存于 config/mcp.php，
 * 与 config/store.php 同构、同在被 git 忽略的 /config 目录。
 *
 * @package App\Controller\Admin\Api
 */
#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class Mcp extends Manage
{
    /**
     * config/mcp.php 绝对路径。
     */
    private function configPath(): string
    {
        return BASE_PATH . "/config/mcp.php";
    }

    /**
     * @return array
     */
    public function info(): array
    {
        return $this->json(data: $this->payload((array)config("mcp")));
    }

    /**
     * 生成/重置访问秘钥（重置即刻吊销旧秘钥）。
     * @return array
     * @throws JSONException
     */
    public function reset(): array
    {
        $cfg = (array)config("mcp");
        $data = [
            "access_key" => bin2hex(random_bytes(32)),
            "enabled" => true,
            "key_version" => (int)($cfg['key_version'] ?? 0) + 1,
            "created_at" => Date::current(),
        ];

        $path = $this->configPath();
        setConfig($data, $path);
        @chmod($path, 0600);
        Opcache::invalidate($path);

        ManageLog::log($this->getManage(), "重置了MCP访问秘钥(v{$data['key_version']})");

        //config() 本请求已缓存旧值，直接用刚生成的 $data 返回
        return $this->json(200, "新的访问秘钥已生成", $this->payload($data));
    }

    /**
     * 启用/停用 MCP 接口（保留秘钥）。
     * @return array
     * @throws JSONException
     */
    public function toggle(): array
    {
        $cfg = (array)config("mcp");
        if (empty($cfg['access_key'])) {
            throw new JSONException("请先生成访问秘钥");
        }

        $enabled = !((bool)($cfg['enabled'] ?? false));
        $path = $this->configPath();
        setConfig(["enabled" => $enabled], $path);
        @chmod($path, 0600);
        Opcache::invalidate($path);

        ManageLog::log($this->getManage(), ($enabled ? "启用" : "停用") . "了MCP接口");

        $cfg['enabled'] = $enabled;
        return $this->json(200, $enabled ? "已启用" : "已停用", $this->payload($cfg));
    }

    /**
     * 组装前端展示数据。
     * @param array $cfg
     * @return array
     */
    private function payload(array $cfg): array
    {
        $url = Client::getUrl() . "/admin/mcp/server";
        return [
            "configured" => !empty($cfg['access_key']),
            "enabled" => (bool)($cfg['enabled'] ?? false),
            "url" => $url,
            "access_key" => (string)($cfg['access_key'] ?? ""),
            "created_at" => (string)($cfg['created_at'] ?? ""),
            "key_version" => (int)($cfg['key_version'] ?? 0),
            "https" => str_starts_with($url, "https://"),
        ];
    }
}
