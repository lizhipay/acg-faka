<?php
declare(strict_types=1);

namespace App\Util;

use Kernel\Exception\JSONException;

/**
 * 把本机的插件目录直接打成商店要的 zip。
 *
 * 开发者中心原本要求作者自己 zip 好、再以 base64 传进来，实际很难用：
 * 一个带音频/图片资源的插件动辄 200KB+，base64 之后 260KB+，
 * AI 工具那条路根本传不动，人手打包又容易漏掉排除项（日志、Config.php、大二进制）。
 * 这个类把打包搬到服务端 —— 调用方只给 plugin_id 和版本号就够了。
 *
 * 三种插件的目录和版本文件都不一样，见 sourceDir() / syncVersion()。
 *
 * @package App\Util
 */
class PluginPacker
{
    public const TYPE_PLUGIN = 0;
    public const TYPE_PAY = 1;
    public const TYPE_THEME = 2;

    /**
     * 打出来的包体积上限，与 MCP 侧一致。
     */
    private const MAX_BYTES = 15 * 1024 * 1024;

    /**
     * 任何插件都不该进包的东西。
     *
     * runtime.log 是框架约定的插件日志位置（后台「日志」按钮读它），
     * 里面全是打包机的绝对路径和栈回溯，进包等于随包发给每一个用户。
     */
    private const GLOBAL_EXCLUDES = [
        'runtime.log',
        '.DS_Store',
        'Thumbs.db',
    ];

    /**
     * 个别插件目录里躺着不该进商店包的大文件。
     * ThreadManager 自带一个 122MB 的 swoole-cli（二进制走 ./service.sh install 单独下载）。
     */
    private const PLUGIN_EXCLUDES = [
        'ThreadManager' => ['swoole-cli', 'swoole-cli.part'],
    ];

    /**
     * 用 plugin_id 向商店反查 plugin_key / type —— 打包要靠它们定位目录。
     * 商店没有「按 id 查单个」的开发者接口，只能翻列表。
     *
     * MCP 工具和后台开发者中心两条路都用这一份，避免两边对不上。
     *
     * @return array{plugin_key: string, type: int, plugin_name: string, status: int}
     * @throws JSONException
     */
    public static function resolveFromStore(\App\Service\App $app, int $pluginId): array
    {
        if ($pluginId <= 0) {
            throw new JSONException("插件 id 无效");
        }

        for ($page = 1; $page <= 20; $page++) {
            $result = $app->developerPlugins(["page" => $page, "limit" => 100]);
            $rows = (array)($result['rows'] ?? []);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if ((int)($row['id'] ?? 0) === $pluginId) {
                    $key = trim((string)($row['plugin_key'] ?? ""));
                    if ($key === "") {
                        throw new JSONException("插件 {$pluginId} 没有 plugin_key，无法定位目录");
                    }
                    return [
                        "plugin_key" => $key,
                        "type" => (int)($row['type'] ?? 0),
                        "plugin_name" => (string)($row['plugin_name'] ?? ""),
                        "status" => (int)($row['status'] ?? 0),
                    ];
                }
            }

            if (count($rows) < 100) {
                break;
            }
        }

        throw new JSONException("在你名下没找到 id={$pluginId} 的插件");
    }

    /**
     * 插件源码目录。与 App\Service\Bind\App::updatePlugin() 的映射保持一致。
     *
     * @throws JSONException
     */
    public static function sourceDir(string $pluginKey, int $type): string
    {
        //key 来自商店返回，不是调用方直接给的，但仍然挡一道路径穿越
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $pluginKey)) {
            throw new JSONException("插件标识不合法：{$pluginKey}");
        }

        $dir = match ($type) {
            self::TYPE_PLUGIN => BASE_PATH . "/app/Plugin/{$pluginKey}",
            self::TYPE_PAY => BASE_PATH . "/app/Pay/{$pluginKey}",
            self::TYPE_THEME => BASE_PATH . "/app/View/User/Theme/{$pluginKey}",
            default => throw new JSONException("未知的插件类型：{$type}"),
        };

        $real = realpath($dir);
        if ($real === false || !is_dir($real)) {
            throw new JSONException("本机找不到插件目录：{$dir}，请确认插件就装在这台服务器上");
        }

        //realpath 之后再确认没跑出站点根目录（软链接也挡住）
        $root = realpath(BASE_PATH);
        if ($root === false || !str_starts_with($real . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
            throw new JSONException("插件目录不在站点内：{$dir}");
        }

        return $real;
    }

    /**
     * 把开发者填的版本号写回插件自己的版本文件。
     *
     * 商店审核要求「包内版本号」与提交的版本号一致，这一步就是让两者不可能对不上；
     * 顺带治了一个老毛病：作者在商店改了版本号却忘了改 Info.php，
     * 下次再发版就不知道本地这份到底对应线上哪一版。
     *
     * @return array{file: string, old: string, new: string, changed: bool}
     * @throws JSONException
     */
    public static function syncVersion(string $dir, int $type, string $version): array
    {
        if (!preg_match('/^[0-9]+(\.[0-9]+)*$/', $version)) {
            throw new JSONException("版本号格式不正确：{$version}（应形如 1.0.4）");
        }

        [$file, $pattern] = match ($type) {
            //通用扩展：Config/Info.php 里的 Plugin::VERSION => '1.0.0'
            self::TYPE_PLUGIN => [
                $dir . '/Config/Info.php',
                '/(Plugin::VERSION\s*=>\s*)([\'"])([^\'"]*)\2/',
            ],
            //支付扩展：Config/Info.php 里的 'version' => '1.0.1'
            self::TYPE_PAY => [
                $dir . '/Config/Info.php',
                '/([\'"]version[\'"]\s*=>\s*)([\'"])([^\'"]*)\2/i',
            ],
            //网站模版：Config.php 的 const INFO 里 "VERSION" => "2.3.2"
            self::TYPE_THEME => [
                $dir . '/Config.php',
                '/([\'"]VERSION[\'"]\s*=>\s*)([\'"])([^\'"]*)\2/',
            ],
            default => throw new JSONException("未知的插件类型：{$type}"),
        };

        if (!is_file($file)) {
            throw new JSONException("找不到版本文件：" . self::relative($file));
        }

        $src = (string)file_get_contents($file);
        if (!preg_match($pattern, $src, $m)) {
            throw new JSONException("没能在 " . self::relative($file) . " 里定位版本号，请手工改好后重试");
        }

        $old = (string)$m[3];
        if ($old === $version) {
            return ['file' => self::relative($file), 'old' => $old, 'new' => $version, 'changed' => false];
        }

        $out = preg_replace($pattern, '${1}${2}' . $version . '${2}', $src, 1);
        if (!is_string($out) || $out === '') {
            throw new JSONException("版本号替换失败：" . self::relative($file));
        }

        //先验一遍语法再落盘，避免把一个跑不起来的 Info.php 写进生产站
        if (!self::lintOk($out)) {
            throw new JSONException("改完版本号后 " . self::relative($file) . " 语法不通过，已放弃写入");
        }

        if (file_put_contents($file, $out) === false) {
            throw new JSONException("写入失败（检查权限）：" . self::relative($file));
        }
        Opcache::invalidate($file);

        return ['file' => self::relative($file), 'old' => $old, 'new' => $version, 'changed' => true];
    }

    /**
     * 打包。返回 zip 的原始字节。
     *
     * @param bool $isUpdate true=更新包，false=安装包。差别只在 Config.php：
     *   - 安装包：写成空的 `return [];`（要有这个文件，但绝不能带作者自己的密钥和状态）
     *   - 更新包：整个不进包（商店硬性要求，装到用户站上不能覆盖人家的配置）
     * @throws JSONException
     */
    public static function pack(string $dir, int $type, string $pluginKey, bool $isUpdate): string
    {
        if (!class_exists('ZipArchive')) {
            throw new JSONException("服务器未安装 php-zip 扩展，无法自动打包");
        }

        $tmpDir = BASE_PATH . '/runtime/tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0777, true);
        }
        $tmp = $tmpDir . '/mcp_pack_' . $pluginKey . '_' . getmypid() . '.zip';
        @unlink($tmp);

        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new JSONException("创建临时 zip 失败，请检查 runtime/tmp 写入权限");
        }

        //只有通用/支付扩展才有「用户配置」这一说；
        //模版的 Config.php 是接口定义（const INFO 就在里面），清空会直接毁掉模版
        $configRelative = in_array($type, [self::TYPE_PLUGIN, self::TYPE_PAY], true)
            ? 'Config/Config.php'
            : null;

        $excludes = array_merge(
            self::GLOBAL_EXCLUDES,
            self::PLUGIN_EXCLUDES[$pluginKey] ?? []
        );

        $hasConfig = false;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $abs = $item->getPathname();
            $rel = str_replace('\\', '/', substr($abs, strlen($dir) + 1));

            if ($rel === '' || self::excluded($rel, $excludes)) {
                continue;
            }

            if ($item->isDir()) {
                $zip->addEmptyDir($rel);
                continue;
            }

            if (!$item->isFile()) {
                continue;
            }

            if ($configRelative !== null && $rel === $configRelative) {
                $hasConfig = true;
                //更新包直接跳过；安装包稍后统一写一份空的
                continue;
            }

            $zip->addFile($abs, $rel);
        }

        //安装包补一份空配置。没有原文件也补 —— 插件装到用户站上本来就该是空配置起步
        if ($configRelative !== null && !$isUpdate) {
            $zip->addFromString($configRelative, self::emptyConfig());
        }

        if (!$zip->close()) {
            @unlink($tmp);
            throw new JSONException("zip 写入失败");
        }

        $bytes = (string)file_get_contents($tmp);
        @unlink($tmp);

        if ($bytes === '' || !str_starts_with($bytes, 'PK')) {
            throw new JSONException("打包结果不是有效的 zip");
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new JSONException("打出来的包 " . round(strlen($bytes) / 1024 / 1024, 1) . "MB，超过 15MB 上限");
        }

        unset($hasConfig);
        return $bytes;
    }

    /**
     * 打包后的自检信息，回给调用方看，省得再解一遍包。
     *
     * @return array{files: int, bytes: int, has_config: bool, entries: array}
     */
    public static function inspect(string $bytes): array
    {
        $tmp = BASE_PATH . '/runtime/tmp/mcp_inspect_' . getmypid() . '.zip';
        file_put_contents($tmp, $bytes);

        $zip = new \ZipArchive();
        $entries = [];
        $hasConfig = false;
        if ($zip->open($tmp) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                $entries[] = $name;
                if ($name === 'Config/Config.php') {
                    $hasConfig = true;
                }
            }
            $zip->close();
        }
        @unlink($tmp);

        return [
            'files' => count($entries),
            'bytes' => strlen($bytes),
            'has_config' => $hasConfig,
            'entries' => $entries,
        ];
    }

    private static function emptyConfig(): string
    {
        return "<?php\ndeclare (strict_types=1);\n\nreturn [];\n";
    }

    /**
     * 排除判定：既比对完整相对路径，也比对文件名本身（.DS_Store 可能出现在任意子目录），
     * 另外 *.log 一律不进包。
     */
    private static function excluded(string $rel, array $excludes): bool
    {
        $base = basename($rel);

        foreach ($excludes as $ex) {
            if ($rel === $ex || $base === $ex || str_starts_with($rel, $ex . '/')) {
                return true;
            }
        }

        return str_ends_with(strtolower($base), '.log');
    }

    /**
     * 用 php -l 之外的办法做语法体检：把内容丢给 token 解析器。
     * 生产站上不能 shell_exec，所以走 PhpToken/token_get_all。
     */
    private static function lintOk(string $code): bool
    {
        try {
            token_get_all($code, TOKEN_PARSE);
            return true;
        } catch (\ParseError) {
            return false;
        }
    }

    private static function relative(string $path): string
    {
        $root = realpath(BASE_PATH);
        if ($root !== false && str_starts_with($path, $root)) {
            return ltrim(substr($path, strlen($root)), '/\\');
        }
        return basename($path);
    }
}
