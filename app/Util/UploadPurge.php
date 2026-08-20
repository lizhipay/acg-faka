<?php
declare(strict_types=1);

namespace App\Util;

use Kernel\Exception\JSONException;

/**
 * 上传文件的安全删除工具。
 *
 * 这套路径校验 + 隔离区暂存 + 失败回滚的逻辑原本只长在
 * Controller\Admin\Api\File 里。删除工单时也要把附件一并物理删掉，
 * 与其在工单那边照抄一份（安全逻辑抄两份迟早会走样），不如抽到这里，
 * 两边共用同一份实现。方法体是从 File 控制器原样搬过来的，逻辑未改。
 * 见 issue #828。
 */
class UploadPurge
{
    /** 只有这两个目录下的文件允许被删除，其余一律判定为危险路径 */
    public const UPLOAD_ROOTS = [
        '/assets/cache/general',
        '/assets/cache/user',
    ];

    public static function thumbnailPath(string $path): string
    {
        return dirname($path) . '/thumb/' . basename($path);
    }

    public static function inspectUploadPath(string $path): array
    {
        $allowedRoot = null;
        foreach (self::UPLOAD_ROOTS as $candidateRoot) {
            if (str_starts_with($path, $candidateRoot . '/')) {
                $allowedRoot = $candidateRoot;
                break;
            }
        }
        $root = $allowedRoot === null ? false : realpath(BASE_PATH . $allowedRoot);
        $safe = $allowedRoot !== null
            && $root !== false
            && strlen($path) <= 255
            && preg_match('#^' . preg_quote($allowedRoot, '#') . '/(?:[A-Za-z0-9][A-Za-z0-9._-]*/)*[A-Za-z0-9][A-Za-z0-9._-]*$#D', $path) === 1;
        $candidate = $safe ? BASE_PATH . $path : '';
        if ($safe) {
            // inspectUploadPath can run twice in one delete request. Do not let
            // PHP's per-request stat/realpath caches hide a concurrent change.
            clearstatcache(true, $candidate);
        }
        if (!$safe || is_link($candidate)) {
            return ['safe' => false, 'exists' => false, 'url' => null, 'real_path' => null, 'identity' => null];
        }

        $real = realpath($candidate);
        if ($real !== false) {
            if (!self::pathInsideRoot($real, $root) || !is_file($real)) {
                return ['safe' => false, 'exists' => false, 'url' => null, 'real_path' => null, 'identity' => null];
            }
            $stat = @stat($real);
            if (!is_array($stat)) {
                return ['safe' => false, 'exists' => false, 'url' => null, 'real_path' => null, 'identity' => null];
            }
            $identity = implode(':', [
                (string)($stat['dev'] ?? ''),
                (string)($stat['ino'] ?? ''),
                (string)($stat['size'] ?? ''),
                (string)($stat['mtime'] ?? ''),
            ]);
            return ['safe' => true, 'exists' => true, 'url' => $path, 'real_path' => $real, 'identity' => $identity];
        }

        $parent = dirname($candidate);
        while (!file_exists($parent) && $parent !== dirname($parent)) {
            $parent = dirname($parent);
        }
        $realParent = realpath($parent);
        if ($realParent === false || !self::pathInsideRoot($realParent, $root)) {
            return ['safe' => false, 'exists' => false, 'url' => null, 'real_path' => null, 'identity' => null];
        }
        return ['safe' => true, 'exists' => false, 'url' => $path, 'real_path' => null, 'identity' => null];
    }

    public static function pathInsideRoot(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    public static function stageForDeletion(
        iterable $rows,
        array $expectedInspections,
        array $expectedThumbnailInspections
    ): array
    {
        $quarantine = BASE_PATH . '/runtime/file-delete-quarantine';
        if (!is_dir($quarantine) && !mkdir($quarantine, 0700, true) && !is_dir($quarantine)) {
            throw new JSONException('无法创建非公开文件隔离区，已阻止删除');
        }
        @chmod($quarantine, 0700);

        $targets = [];
        foreach ($rows as $row) {
            $id = (int)$row->id;
            $current = self::inspectUploadPath((string)$row->path);
            $expected = $expectedInspections[$id] ?? null;
            if (!$current['safe'] || !$expected || !$expected['safe']
                || $current['url'] !== $expected['url']
                || $current['exists'] !== $expected['exists']
                || $current['identity'] !== $expected['identity']) {
                throw new JSONException('文件路径或状态已变化，未执行删除，请重新预览');
            }
            if ($current['exists']) {
                $targets[$current['real_path']] = $current['real_path'];
            }
            $thumb = self::inspectUploadPath(self::thumbnailPath($current['url']));
            $expectedThumb = $expectedThumbnailInspections[$id] ?? null;
            if (!$thumb['safe'] || !$expectedThumb || !$expectedThumb['safe']
                || $thumb['url'] !== $expectedThumb['url']
                || $thumb['exists'] !== $expectedThumb['exists']
                || $thumb['identity'] !== $expectedThumb['identity']) {
                throw new JSONException('缩略图路径或状态已变化，已阻止删除，请重新预览');
            }
            if ($thumb['exists']) {
                $targets[$thumb['real_path']] = $thumb['real_path'];
            }
        }

        $staged = [];
        try {
            foreach ($targets as $source) {
                $target = $quarantine . '/' . bin2hex(random_bytes(16)) . '.delete';
                if (!@rename($source, $target)) {
                    throw new JSONException('文件无法原子移入隔离区，整批删除已取消');
                }
                @chmod($target, 0600);
                $staged[] = ['source' => $source, 'target' => $target];
            }
        } catch (\Throwable $throwable) {
            $restoreFailures = self::restoreStagedFiles($staged);
            if ($restoreFailures > 0) {
                throw new JSONException("文件隔离失败，且有 {$restoreFailures} 个文件无法恢复，请立即联系运维处理");
            }
            if ($throwable instanceof JSONException) {
                throw $throwable;
            }
            throw new JSONException('无法安全隔离待删除文件，整批删除已取消');
        }
        return $staged;
    }

    public static function restoreStagedFiles(array $staged): int
    {
        $failures = 0;
        foreach (array_reverse($staged) as $item) {
            if (!is_file($item['target'])) {
                continue;
            }
            if (file_exists($item['source']) || !@rename($item['target'], $item['source'])) {
                $failures++;
            }
        }
        return $failures;
    }

    public static function purgeStagedFiles(array $staged): int
    {
        $failures = 0;
        foreach ($staged as $item) {
            if (is_file($item['target']) && !@unlink($item['target'])) {
                $failures++;
            }
        }
        return $failures;
    }
}
