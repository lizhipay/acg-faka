<?php
declare(strict_types=1);

namespace App\Service\Bind;

use App\Util\PluginPacker;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;

/**
 * 开发者中心 MCP 工具实现。
 *
 * 校验入参 → 复用 \App\Service\App 的开发者方法中转到商店。
 *
 * 图标仍以 base64 传入，原始字节直接作为 icon 字段（与后台 developerCreatePlugin 一致）。
 *
 * 插件包**默认由服务端自己打**（PluginPacker）：调用方只给 plugin_id 和版本号，
 * 服务端按 id 反查 plugin_key/type、定位本机插件目录、同步版本号、打包、直传商店。
 * 原来那条「作者自己压好再传 base64」的路保留为可选兜底（插件不在本机时用），
 * 但不再是主路 —— 一个带音视频资源的插件 base64 之后轻松 260KB+，
 * AI 工具那条链路根本传不动，人手打包也容易漏排除项。
 *
 * 拿到字节后以 Guzzle multipart 直传商店 /open/project/upload，换回临时 path，
 * 再提交 createKit / createUpdate。
 *
 * 另有一组「本地插件运维」工具（local_plugins / plugin_start / plugin_stop /
 * plugin_config_get / plugin_config_set / plugin_log_read / plugin_log_clear），
 * 不经商店、直接操作本机 app/Plugin 下的通用插件，与后台「功能插件」页同一套内核
 * 流程（_plugin_start/_plugin_stop、SAVE_CONFIG 钩子链、runtime.log 约定）。
 * 安全边界：plugin_key 白名单校验 + realpath 圈禁；配置读取默认脱敏（防提示注入
 * 拖走支付密钥），reveal_sensitive=true 才给真实值且会被审计；STATUS 不允许经
 * config_set 绕过启停校验。
 *
 * @package App\Service\Bind
 */
class Mcp implements \App\Service\Mcp
{
    #[Inject]
    private \App\Service\App $app;

    /**
     * 图标解码后体积上限（2MB）。
     */
    private const MAX_ICON_BYTES = 2 * 1024 * 1024;

    /**
     * 插件包解码后体积上限（15MB，留出余量给商店 16MB 整包上限）。
     */
    private const MAX_PACKAGE_BYTES = 15 * 1024 * 1024;

    /**
     * @return array
     */
    public function tools(): array
    {
        return [
            [
                "name" => "list_plugins",
                "description" => "列出当前开发者账号名下的所有插件及其状态。返回每个插件的 id、plugin_key、plugin_name、type、version、price、group、status。其它工具需要的 plugin_id 从这里获取。status：0=开发中，1=已上架，2=驳回，3=审核中。type：0=通用扩展，1=支付扩展，2=网站模版。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "page" => ["type" => "integer", "minimum" => 1, "default" => 1, "description" => "页码，从 1 开始"],
                        "limit" => ["type" => "integer", "minimum" => 1, "maximum" => 100, "default" => 20, "description" => "每页数量，最多 100"],
                    ],
                ],
            ],
            [
                "name" => "create_plugin",
                "description" => "创建一个新插件（初始状态=开发中）。创建成功后需再用 upload_install_kit 上传安装包提交审核。plugin_key 一旦占用不可更改。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "plugin_key" => ["type" => "string", "pattern" => "^[A-Za-z]+$", "minLength" => 4, "maxLength" => 32, "description" => "插件唯一标识，仅英文字母，须与插件文件夹名一致"],
                        "plugin_name" => ["type" => "string", "minLength" => 4, "maxLength" => 32, "description" => "插件名称"],
                        "type" => ["type" => "integer", "enum" => [0, 1, 2], "description" => "0=通用扩展，1=支付扩展，2=网站模版"],
                        "group" => ["type" => "integer", "enum" => [0, 1, 2], "default" => 0, "description" => "0=不启用，1=专业版/企业版免费，2=企业版免费"],
                        "version" => ["type" => "string", "default" => "1.0.0", "description" => "版本号，如 1.0.0"],
                        "description" => ["type" => "string", "maxLength" => 60, "description" => "插件简介，60 字以内"],
                        "web_site" => ["type" => "string", "default" => "#", "description" => "插件官网/演示地址，可留空"],
                        "price" => ["type" => "number", "minimum" => 0, "default" => 0, "description" => "市场售价，0=免费"],
                        "icon_base64" => ["type" => "string", "description" => "插件图标图片的 base64（png/jpg/gif，建议 120x120，≤2MB）"],
                    ],
                    "required" => ["plugin_key", "plugin_name", "type", "description", "icon_base64"],
                ],
            ],
            [
                "name" => "upload_install_kit",
                "description" => "为一个处于「开发中」(status=0) 的插件上传安装包并提交审核，提交后状态变为审核中(3)。默认由服务端直接从本机插件目录自动打包，不需要你自己压缩、更不需要传 base64——只给 plugin_id 即可。打包时会自动排除日志等运行态文件，并把 Config.php 写成空的 return []; （绝不会带上本站的密钥和启用状态，也不会改动本机那份配置）。填了 version 就会先把该版本号写回插件自己的 Info，保证包内版本与商店一致。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "plugin_id" => ["type" => "integer", "description" => "插件 id（来自 list_plugins）"],
                        "version" => ["type" => "string", "maxLength" => 32, "description" => "版本号，如 1.0.4。填了就写回插件的 Info 再打包；不填则用插件当前的版本号"],
                        "package_base64" => ["type" => "string", "description" => "可选。仅当插件不在本机、需要你自带压缩包时才用；留空即走服务端自动打包"],
                    ],
                    "required" => ["plugin_id"],
                ],
            ],
            [
                "name" => "submit_update",
                "description" => "为一个「已上架」(status=1) 的插件提交更新包进入审核。默认由服务端直接从本机插件目录自动打包，不需要你自己压缩、更不需要传 base64。audit_version 会先被写回插件自己的 Info 再打包，所以包内版本号与提交版本号必定一致。更新包会自动剔除 Config.php（不能覆盖用户站点的配置）和日志等运行态文件。更新包若改动数据库，需自行在插件根目录放好累计的 update.sql，它会被一起打进去。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "plugin_id" => ["type" => "integer", "description" => "插件 id（来自 list_plugins）"],
                        "audit_version" => ["type" => "string", "maxLength" => 32, "description" => "本次更新的版本号，如 1.0.4。会自动同步进插件的 Info"],
                        "audit_update_content" => ["type" => "string", "description" => "更新说明（必填，用户可见）"],
                        "package_base64" => ["type" => "string", "description" => "可选。仅当插件不在本机、需要你自带压缩包时才用；留空即走服务端自动打包"],
                    ],
                    "required" => ["plugin_id", "audit_version", "audit_update_content"],
                ],
            ],
            [
                "name" => "set_price",
                "description" => "修改自己插件的市场售价。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "plugin_id" => ["type" => "integer", "description" => "插件 id（来自 list_plugins）"],
                        "price" => ["type" => "number", "minimum" => 0, "description" => "市场售价，0=免费"],
                    ],
                    "required" => ["plugin_id", "price"],
                ],
            ],
            [
                "name" => "local_plugins",
                "description" => "列出本机已安装的通用插件（app/Plugin 目录）及运行状态。本地运维类工具（启停/配置/日志）用这里的 plugin_key 定位插件。status：1=运行中，0=已停止。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => new \stdClass(),
                ],
            ],
            [
                "name" => "plugin_start",
                "description" => "启动本机的一个通用插件（等同后台「功能插件」页的启动按钮）。启动会向应用商店校验授权：未授权的插件会保持停止状态。已在运行的插件直接返回，不重复启动。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "plugin_key" => ["type" => "string", "description" => "插件标识（来自 local_plugins）"],
                    ],
                    "required" => ["plugin_key"],
                ],
            ],
            [
                "name" => "plugin_stop",
                "description" => "停止本机的一个通用插件（等同后台「功能插件」页的停止按钮）。已停止的插件直接返回。注意：停止会即刻移除该插件的所有钩子，站点相关功能随之下线。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "plugin_key" => ["type" => "string", "description" => "插件标识（来自 local_plugins）"],
                    ],
                    "required" => ["plugin_key"],
                ],
            ],
            [
                "name" => "plugin_config_get",
                "description" => "读取本机通用插件的配置（Config/Config.php）。默认对密钥/令牌类字段脱敏为 ***；确需真实值时传 reveal_sensitive=true（该行为会被审计记录）。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "plugin_key" => ["type" => "string", "description" => "插件标识（来自 local_plugins）"],
                        "reveal_sensitive" => ["type" => "boolean", "default" => false, "description" => "true=返回敏感字段真实值（默认脱敏）"],
                    ],
                    "required" => ["plugin_key"],
                ],
            ],
            [
                "name" => "plugin_config_set",
                "description" => "修改本机通用插件的配置：传入要变更的键值对，未提及的键保持不变（合并写入，与后台保存配置行为一致，同样会触发插件的 SAVE_CONFIG 钩子）。STATUS（运行状态）不允许在这里改，请用 plugin_start / plugin_stop。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "plugin_key" => ["type" => "string", "description" => "插件标识（来自 local_plugins）"],
                        "config" => ["type" => "object", "description" => "要变更的配置键值对，例如 {\"mode\":\"1\",\"api_url\":\"https://...\"}"],
                    ],
                    "required" => ["plugin_key", "config"],
                ],
            ],
            [
                "name" => "plugin_log_read",
                "description" => "读取本机通用插件的运行日志（插件目录下的 runtime.log，与后台「日志」按钮同源）。返回末尾 N 行。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "plugin_key" => ["type" => "string", "description" => "插件标识（来自 local_plugins）"],
                        "lines" => ["type" => "integer", "minimum" => 1, "maximum" => 2000, "default" => 200, "description" => "返回末尾多少行，默认 200"],
                    ],
                    "required" => ["plugin_key"],
                ],
            ],
            [
                "name" => "plugin_log_clear",
                "description" => "清空本机通用插件的运行日志（删除插件目录下的 runtime.log，插件下次写日志时会自动重建）。清空不可恢复。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "plugin_key" => ["type" => "string", "description" => "插件标识（来自 local_plugins）"],
                    ],
                    "required" => ["plugin_key"],
                ],
            ],
        ];
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return array
     * @throws JSONException
     */
    public function call(string $name, array $arguments): array
    {
        $storeTools = ["list_plugins", "create_plugin", "upload_install_kit", "submit_update", "set_price"];

        //商店中转与插件启停都依赖已授权的加密内核（_plugin_* 函数），离线时直接拒绝；
        //配置/日志类是纯本地文件操作，离线也能用
        if (in_array($name, [...$storeTools, "plugin_start", "plugin_stop"], true)
            && !file_exists(BASE_PATH . "/kernel/Plugin.php")) {
            throw new JSONException("应用商店已离线，无法使用该工具");
        }

        if (in_array($name, $storeTools, true)) {
            $store = (array)config("store");
            if (empty($store['app_id']) || empty($store['app_key'])) {
                throw new JSONException("本站尚未登录应用商店，请先在「应用商店」登录后再使用");
            }
        }

        return match ($name) {
            "list_plugins" => $this->listPlugins($arguments),
            "create_plugin" => $this->createPlugin($arguments),
            "upload_install_kit" => $this->uploadInstallKit($arguments),
            "submit_update" => $this->submitUpdate($arguments),
            "set_price" => $this->setPrice($arguments),
            "local_plugins" => $this->localPlugins(),
            "plugin_start" => $this->pluginStart($arguments),
            "plugin_stop" => $this->pluginStop($arguments),
            "plugin_config_get" => $this->pluginConfigGet($arguments),
            "plugin_config_set" => $this->pluginConfigSet($arguments),
            "plugin_log_read" => $this->pluginLogRead($arguments),
            "plugin_log_clear" => $this->pluginLogClear($arguments),
            default => throw new JSONException("未知的工具：{$name}"),
        };
    }

    /**
     * @param array $args
     * @return array
     * @throws JSONException
     */
    private function listPlugins(array $args): array
    {
        $page = max(1, (int)($args['page'] ?? 1));
        $limit = (int)($args['limit'] ?? 20);
        $limit = min(100, max(1, $limit));

        $result = $this->app->developerPlugins(["page" => $page, "limit" => $limit]);
        $rows = [];
        foreach ((array)($result['rows'] ?? []) as $row) {
            //只回传对开发者有意义、且不含内部路径的字段
            $rows[] = [
                "id" => (int)($row['id'] ?? 0),
                "plugin_key" => (string)($row['plugin_key'] ?? ""),
                "plugin_name" => (string)($row['plugin_name'] ?? ""),
                "type" => (int)($row['type'] ?? 0),
                "version" => (string)($row['version'] ?? ""),
                "price" => $row['price'] ?? "0",
                "group" => (int)($row['group'] ?? 0),
                "status" => (int)($row['status'] ?? 0),
                "description" => (string)($row['description'] ?? ""),
                "web_site" => (string)($row['web_site'] ?? ""),
                "error_reason" => (string)($row['error_reason'] ?? ""),
            ];
        }

        return [
            "total" => (int)($result['count'] ?? count($rows)),
            "plugins" => $rows,
        ];
    }

    /**
     * @param array $args
     * @return array
     * @throws JSONException
     */
    private function createPlugin(array $args): array
    {
        $pluginKey = trim((string)($args['plugin_key'] ?? ""));
        if (!preg_match('/^[A-Za-z]+$/', $pluginKey) || mb_strlen($pluginKey) < 4 || mb_strlen($pluginKey) > 32) {
            throw new JSONException("plugin_key 仅支持英文字母，长度 4-32 位");
        }

        $pluginName = trim((string)($args['plugin_name'] ?? ""));
        if (mb_strlen($pluginName) < 4 || mb_strlen($pluginName) > 32) {
            throw new JSONException("plugin_name 长度需为 4-32 位");
        }

        $type = (int)($args['type'] ?? -1);
        if (!in_array($type, [0, 1, 2], true)) {
            throw new JSONException("type 只能是 0(通用) / 1(支付) / 2(模版)");
        }

        $group = (int)($args['group'] ?? 0);
        if (!in_array($group, [0, 1, 2], true)) {
            throw new JSONException("group 只能是 0 / 1 / 2");
        }

        $description = trim((string)($args['description'] ?? ""));
        if ($description === "" || mb_strlen($description) > 60) {
            throw new JSONException("description 必填且不超过 60 字");
        }

        $price = $this->normalizePrice($args['price'] ?? 0);
        $version = trim((string)($args['version'] ?? "")) ?: "1.0.0";
        $webSite = trim((string)($args['web_site'] ?? "")) ?: "#";

        //解码图标：直接以原始字节作为 icon 字段（与后台 developerCreatePlugin 一致）
        $icon = $this->decodeBase64((string)($args['icon_base64'] ?? ""), self::MAX_ICON_BYTES, "图标");
        if (getimagesizefromstring($icon) === false) {
            throw new JSONException("icon_base64 不是有效的图片");
        }

        $this->app->developerCreatePlugin([
            "icon" => $icon,
            "plugin_key" => $pluginKey,
            "plugin_name" => $pluginName,
            "type" => $type,
            "group" => $group,
            "version" => $version,
            "description" => $description,
            "web_site" => $webSite,
            "price" => $price,
        ]);

        return ["message" => "插件「{$pluginName}」创建成功，请用 upload_install_kit 上传安装包提交审核"];
    }

    /**
     * @param array $args
     * @return array
     * @throws JSONException
     */
    private function uploadInstallKit(array $args): array
    {
        $pluginId = (int)($args['plugin_id'] ?? 0);
        if ($pluginId <= 0) {
            throw new JSONException("plugin_id 无效");
        }

        $built = $this->buildPackage(
            $pluginId,
            (string)($args['package_base64'] ?? ""),
            trim((string)($args['version'] ?? "")),
            false
        );

        $this->app->developerCreateKit([
            "id" => $pluginId,
            "resource" => $built['path'],
        ]);

        return [
            "message" => "安装包已提交，插件进入审核中(status=3)",
            "package" => $built['summary'],
        ];
    }

    /**
     * @param array $args
     * @return array
     * @throws JSONException
     */
    private function submitUpdate(array $args): array
    {
        $pluginId = (int)($args['plugin_id'] ?? 0);
        if ($pluginId <= 0) {
            throw new JSONException("plugin_id 无效");
        }

        $auditVersion = trim((string)($args['audit_version'] ?? ""));
        if ($auditVersion === "" || mb_strlen($auditVersion) > 32) {
            throw new JSONException("audit_version 必填且不超过 32 位");
        }

        $auditUpdateContent = trim((string)($args['audit_update_content'] ?? ""));
        if ($auditUpdateContent === "") {
            throw new JSONException("audit_update_content（更新说明）必填");
        }

        $built = $this->buildPackage(
            $pluginId,
            (string)($args['package_base64'] ?? ""),
            $auditVersion,
            true
        );

        $this->app->developerUpdatePlugin([
            "id" => $pluginId,
            "audit_resource" => $built['path'],
            "audit_version" => $auditVersion,
            "audit_update_content" => $auditUpdateContent,
        ]);

        return [
            "message" => "更新包已提交，等待审核",
            "package" => $built['summary'],
        ];
    }

    /**
     * 拿到可提交的包：有 base64 就用调用方给的，否则从本机插件目录现打一个。
     *
     * @param string $version 非空则先写回插件自己的版本文件，再打包 —— 这样「包内版本号」
     *                        和提交给商店的版本号不可能对不上（商店会卡这一条）
     * @param bool $isUpdate true=更新包（剔除 Config.php），false=安装包（Config.php 清空成 return [];）
     * @return array{path: string, summary: array}
     * @throws JSONException
     */
    private function buildPackage(int $pluginId, string $base64, string $version, bool $isUpdate): array
    {
        //调用方自带压缩包时完全按老路走，不碰本机任何文件
        if (trim($base64) !== "") {
            return [
                "path" => $this->uploadPackage($base64),
                "summary" => ["source" => "调用方提供的压缩包"],
            ];
        }

        $plugin = PluginPacker::resolveFromStore($this->app, $pluginId);
        $type = (int)$plugin['type'];
        $key = (string)$plugin['plugin_key'];
        $dir = PluginPacker::sourceDir($key, $type);

        $summary = [
            "source" => "服务端自动打包",
            "plugin_key" => $key,
            "directory" => str_replace(BASE_PATH, "", $dir),
        ];

        if ($version !== "") {
            $sync = PluginPacker::syncVersion($dir, $type, $version);
            $summary['version_file'] = $sync['file'];
            $summary['version'] = $sync['changed']
                ? "{$sync['old']} → {$sync['new']}"
                : "{$sync['new']}（本来就是这个版本，未改动）";
        }

        $bytes = PluginPacker::pack($dir, $type, $key, $isUpdate);
        $info = PluginPacker::inspect($bytes);

        $summary['files'] = $info['files'];
        $summary['size'] = round($info['bytes'] / 1024, 1) . " KB";
        $summary['config_php'] = $isUpdate
            ? ($info['has_config'] ? "⚠ 仍在包内（不该出现）" : "已剔除")
            : ($info['has_config'] ? "已清空为 return [];" : "无此文件");

        $upload = $this->app->upload([
            [
                "name" => "file",
                "contents" => $bytes,
                "filename" => "file.zip",
            ],
        ]);

        $path = (string)($upload['path'] ?? "");
        if ($path === "") {
            throw new JSONException("插件包上传失败，商店未返回路径");
        }

        return ["path" => $path, "summary" => $summary];
    }


    /**
     * @param array $args
     * @return array
     * @throws JSONException
     */
    private function setPrice(array $args): array
    {
        $pluginId = (int)($args['plugin_id'] ?? 0);
        if ($pluginId <= 0) {
            throw new JSONException("plugin_id 无效");
        }
        $price = $this->normalizePrice($args['price'] ?? null);

        $this->app->developerPluginPriceSet([
            "id" => $pluginId,
            "price" => $price,
        ]);

        return ["message" => "新的定价已生效：" . ($price > 0 ? $price : "免费")];
    }

    /* ==================== 本地插件运维（不经商店） ==================== */

    /**
     * @return array
     */
    private function localPlugins(): array
    {
        $plugins = (array)\Kernel\Util\Plugin::getPlugins(false);
        $rows = [];
        foreach ($plugins as $plugin) {
            $key = (string)($plugin['PLUGIN_NAME'] ?? "");
            if ($key === "") {
                continue;
            }
            $log = BASE_PATH . "/app/Plugin/{$key}/runtime.log";
            $rows[] = [
                "plugin_key" => $key,
                "name" => (string)($plugin[\App\Consts\Plugin::NAME] ?? $key),
                "version" => (string)($plugin[\App\Consts\Plugin::VERSION] ?? ""),
                "status" => (int)($plugin['PLUGIN_CONFIG']['STATUS'] ?? 0),
                "log_bytes" => is_file($log) ? (int)filesize($log) : 0,
            ];
        }
        return ["total" => count($rows), "plugins" => $rows];
    }

    /**
     * @param array $args
     * @return array
     * @throws JSONException
     */
    private function pluginStart(array $args): array
    {
        [$key, $plugin] = $this->resolveLocalPlugin($args);

        if ((int)($plugin['PLUGIN_CONFIG']['STATUS'] ?? 0) === 1) {
            return ["message" => "插件「{$key}」已在运行，无需启动"];
        }

        //内核启动流程：向商店校验授权 → 挂钩子 → STATUS=1。
        //未授权时它不报错、也不改状态，所以完成后必须回读真实状态如实汇报
        \_plugin_start($key);

        $fresh = \Kernel\Util\Plugin::getPlugin($key, false);
        $status = (int)($fresh['PLUGIN_CONFIG']['STATUS'] ?? 0);
        if ($status !== 1) {
            throw new JSONException("插件「{$key}」未能启动：应用商店未授权该插件（未购买或授权过期）");
        }
        return ["message" => "插件「{$key}」已启动", "status" => 1];
    }

    /**
     * @param array $args
     * @return array
     * @throws JSONException
     */
    private function pluginStop(array $args): array
    {
        [$key, $plugin] = $this->resolveLocalPlugin($args);

        if ((int)($plugin['PLUGIN_CONFIG']['STATUS'] ?? 0) !== 1) {
            return ["message" => "插件「{$key}」本来就处于停止状态"];
        }

        \_plugin_stop($key);
        return ["message" => "插件「{$key}」已停止，其钩子已全部移除", "status" => 0];
    }

    /**
     * @param array $args
     * @return array
     * @throws JSONException
     */
    private function pluginConfigGet(array $args): array
    {
        [$key, $plugin] = $this->resolveLocalPlugin($args);
        $config = (array)($plugin['PLUGIN_CONFIG'] ?? []);
        $reveal = (bool)($args['reveal_sensitive'] ?? false);

        return [
            "plugin_key" => $key,
            "status" => (int)($config['STATUS'] ?? 0),
            "sensitive_masked" => !$reveal,
            "config" => $reveal ? $config : maskSensitive($config),
        ];
    }

    /**
     * @param array $args
     * @return array
     * @throws JSONException
     */
    private function pluginConfigSet(array $args): array
    {
        [$key, $plugin] = $this->resolveLocalPlugin($args);

        $map = $args['config'] ?? null;
        if (!is_array($map) || $map === []) {
            throw new JSONException("config 必须是非空对象");
        }

        //运行状态必须走 plugin_start/plugin_stop（那里有授权校验和钩子增删），不许在这儿绕过
        foreach (["STATUS"] as $forbidden) {
            if (array_key_exists($forbidden, $map)) {
                throw new JSONException("{$forbidden} 不允许通过配置修改，请使用 plugin_start / plugin_stop");
            }
        }

        $config = (array)($plugin['PLUGIN_CONFIG'] ?? []);
        $changed = [];
        foreach ($map as $k => $v) {
            if (!is_string($k) || $k === "") {
                throw new JSONException("配置键必须是字符串");
            }
            //与后台一致：标量一律落成字符串（配置文件里的既有值全是 '1' 这种形式），数组原样保留
            if (is_scalar($v) || $v === null) {
                $config[$k] = is_bool($v) ? ($v ? "1" : "0") : (string)$v;
            } else {
                $config[$k] = $v;
            }
            $changed[] = $k;
        }

        //与后台保存插件配置同一条钩子链，插件对配置变更的自定义处理不会被绕过
        hook(\App\Consts\Hook::ADMIN_API_PLUGIN_SAVE_CONFIG, $key, $map);
        \Kernel\Util\Plugin::runHookState($key, \Kernel\Annotation\Plugin::SAVE_CONFIG, $key, $map);

        setConfig($config, BASE_PATH . "/app/Plugin/{$key}/Config/Config.php");

        return [
            "message" => "插件「{$key}」配置已保存",
            "changed_keys" => $changed,
        ];
    }

    /**
     * @param array $args
     * @return array
     * @throws JSONException
     */
    private function pluginLogRead(array $args): array
    {
        [$key] = $this->resolveLocalPlugin($args);
        $lines = (int)($args['lines'] ?? 200);
        $lines = min(2000, max(1, $lines));

        $log = $this->pluginLogPath($key);
        if (!is_file($log)) {
            return ["plugin_key" => $key, "exists" => false, "message" => "该插件暂无日志"];
        }

        //只读末尾一段，防止超大日志把整条响应撑爆
        $size = (int)filesize($log);
        $window = 512 * 1024;
        $fp = fopen($log, "r");
        if ($fp === false) {
            throw new JSONException("日志读取失败（检查文件权限）");
        }
        if ($size > $window) {
            fseek($fp, $size - $window);
            fgets($fp); //丢掉可能被截断的半行
        }
        $tail = (string)stream_get_contents($fp);
        fclose($fp);

        $all = preg_split('/\r\n|\r|\n/', rtrim($tail, "\r\n"));
        $all = $all === false ? [] : $all;
        $slice = array_slice($all, -$lines);

        return [
            "plugin_key" => $key,
            "exists" => true,
            "total_bytes" => $size,
            "returned_lines" => count($slice),
            "truncated" => $size > $window || count($all) > count($slice),
            "log" => implode("\n", $slice),
        ];
    }

    /**
     * @param array $args
     * @return array
     * @throws JSONException
     */
    private function pluginLogClear(array $args): array
    {
        [$key] = $this->resolveLocalPlugin($args);
        $log = $this->pluginLogPath($key);

        if (!is_file($log)) {
            return ["plugin_key" => $key, "message" => "该插件本来就没有日志文件"];
        }

        $bytes = (int)filesize($log);
        if (!unlink($log)) {
            throw new JSONException("日志清空失败（检查文件权限）");
        }
        return ["plugin_key" => $key, "message" => "已清空日志（原 " . round($bytes / 1024, 1) . " KB），插件下次写日志时会自动重建"];
    }

    /**
     * 校验 plugin_key 并装载本地插件（含最新 STATUS）。
     * 返回 [插件标识, 插件数据]。
     *
     * @param array $args
     * @return array{0: string, 1: array}
     * @throws JSONException
     */
    private function resolveLocalPlugin(array $args): array
    {
        $key = trim((string)($args['plugin_key'] ?? ""));
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $key)) {
            throw new JSONException("plugin_key 不合法");
        }

        $dir = realpath(BASE_PATH . "/app/Plugin/{$key}");
        $root = realpath(BASE_PATH . "/app/Plugin");
        if ($dir === false || $root === false || !is_dir($dir)
            || !str_starts_with($dir . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
            throw new JSONException("本机没有安装插件「{$key}」");
        }

        $plugin = \Kernel\Util\Plugin::getPlugin($key, false);
        if ($plugin === null) {
            throw new JSONException("插件「{$key}」缺少 Config/Info.php，不是有效的通用插件");
        }

        return [$key, $plugin];
    }

    /**
     * 插件日志固定路径（app/Plugin/<key>/runtime.log），拒绝软链，防穿越。
     *
     * @param string $key
     * @return string
     * @throws JSONException
     */
    private function pluginLogPath(string $key): string
    {
        $dir = realpath(BASE_PATH . "/app/Plugin/{$key}");
        if ($dir === false) {
            throw new JSONException("插件目录不存在");
        }
        $log = $dir . DIRECTORY_SEPARATOR . "runtime.log";
        if (is_link($log)) {
            throw new JSONException("插件日志路径不安全（软链接）");
        }
        if (file_exists($log)) {
            $real = realpath($log);
            if ($real === false || dirname($real) !== $dir) {
                throw new JSONException("插件日志路径不安全");
            }
        }
        return $log;
    }

    /**
     * 解码插件包 base64 并直传商店暂存区，返回商店临时 path。
     * @param string $base64
     * @return string
     * @throws JSONException
     */
    private function uploadPackage(string $base64): string
    {
        $bytes = $this->decodeBase64($base64, self::MAX_PACKAGE_BYTES, "插件包");
        if (!str_starts_with($bytes, "PK")) {
            throw new JSONException("插件包不是有效的 zip 文件");
        }

        $upload = $this->app->upload([
            [
                "name" => "file",
                "contents" => $bytes,
                "filename" => "file.zip",
            ],
        ]);

        $path = (string)($upload['path'] ?? "");
        if ($path === "") {
            throw new JSONException("插件包上传失败，商店未返回路径");
        }
        return $path;
    }

    /**
     * @param string $base64
     * @param int $maxBytes
     * @param string $label
     * @return string
     * @throws JSONException
     */
    private function decodeBase64(string $base64, int $maxBytes, string $label): string
    {
        $base64 = trim($base64);
        //兼容 data URI 前缀，如 data:image/png;base64,xxxx
        if (str_contains($base64, ",") && str_starts_with($base64, "data:")) {
            $base64 = substr($base64, strpos($base64, ",") + 1);
        }
        if ($base64 === "") {
            throw new JSONException("{$label}内容为空");
        }
        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === "") {
            throw new JSONException("{$label} base64 解码失败");
        }
        if (strlen($bytes) > $maxBytes) {
            throw new JSONException("{$label}体积超过上限（" . (int)($maxBytes / 1024 / 1024) . "MB），请改用网页后台上传");
        }
        return $bytes;
    }

    /**
     * @param mixed $price
     * @return float
     * @throws JSONException
     */
    private function normalizePrice(mixed $price): float
    {
        if (!is_numeric($price)) {
            throw new JSONException("price 必须是数字");
        }
        $price = (float)$price;
        if ($price < 0) {
            throw new JSONException("price 不能为负数");
        }
        return $price;
    }
}
