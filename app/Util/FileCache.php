<?php

declare(strict_types=1);

namespace App\Util;

use DirectoryIterator;
use Kernel\Exception\JSONException;

class FileCache
{
    /**
     * 获取Json文件数据
     * @param string $key
     * @param string $name
     * @return array
     */
    public static function getJsonFile(string $key, string $name): array
    {
        $filePath = BASE_PATH . "/runtime/{$key}/{$name}.json";
        if (!file_exists($filePath)) {
            return [];
        }

        $fileContents = json_decode(file_get_contents($filePath), true);
        if ($fileContents['timeout'] != null && $fileContents['timeout'] < time()) {
            unlink($filePath);
            return [];
        }
        return $fileContents['contents'];
    }

    /**
     * 设置Json文件内容
     * @param string $key
     * @param string $name
     * @param array $data
     * @param int $cache
     * @return bool
     */
    public static function setJsonFile(string $key, string $name, array $data = [], int $cache = 0): bool
    {
        try {
            $dirPath = BASE_PATH . "/runtime/{$key}";
            if (!is_dir($dirPath)) {
                mkdir($dirPath, 0777, true);
            }
            $filePath = $dirPath . "/{$name}.json";
            file_put_contents($filePath, json_encode([
                "contents" => $data,
                "timeout" => $cache == 0 ? null : (time() + $cache)
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $throwable) {
            return false;
        }

        return true;
    }

    /**
     * 清理Json缓存目录
     * @param string $key
     * @return bool
     */
    public static function clearCache(string $key): bool
    {
        $dirPath = BASE_PATH . "/runtime/{$key}";

        // 检查目录是否存在
        if (!is_dir($dirPath)) {
            return false;
        }
        // 打开目录
        $dir = new DirectoryIterator($dirPath);

        foreach ($dir as $fileinfo) {
            // 只处理 JSON 文件
            if (!$fileinfo->isDot() && strtolower($fileinfo->getExtension()) == 'json') {
                $filePath = $fileinfo->getPathname();

                // 读取 JSON 文件内容
                $jsonContent = file_get_contents($filePath);
                $data = json_decode($jsonContent, true);

                if (!empty($data)) {
                    // 检查是否包含 'timeout' 字段
                    if (isset($data['timeout'])) {
                        // 检查是否到期
                        if ($data['timeout'] < time()) {
                            unlink($filePath);
                        }
                    }
                }
            }
        }
        return true;
    }
}
