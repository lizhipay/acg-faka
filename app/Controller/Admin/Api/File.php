<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Interceptor\Owner;
use App\Model\ManageLog;
use App\Model\Upload;
use App\Service\Query;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Util\File as FileUtil;

/**
 * 文件管理（对应数据表 acg_upload）
 * @package App\Controller\Admin\Api
 */
#[Interceptor([ManageSession::class, Owner::class], Interceptor::TYPE_API)]
class File extends Manage
{
    /**
     * acg_upload contains both administrator uploads and member uploads. Keep
     * the allow-list explicit so a database path can never escape into another
     * public directory, while preserving management of the complete table.
     */
    private const UPLOAD_ROOTS = [
        '/assets/cache/general',
        '/assets/cache/user',
    ];
    private const MAX_DELETE_COUNT = 200;

    #[Inject]
    private Query $query;

    #[Inject]
    private \App\Service\Upload $upload;

    /**
     * 允许上传的后缀
     */
    const ALLOW_EXT = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'ico', 'svg', 'mp4', 'webm', 'mov', 'mp3', 'zip', 'rar', '7z', 'gz', 'woff', 'woff2', 'ttf', 'otf', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'apk'];

    /**
     * 文件列表（分页 + 搜索），并附带文件大小/是否存在/缩略图/上传者
     * @return array
     */
    public function data(): array
    {
        $map = array_intersect_key($_POST, array_flip([
            'equal-type',
            'search-path',
            'search-note',
            'between-create_time',
        ]));
        $page = max(1, (int)$this->request->post('page'));
        $limit = (int)$this->request->post('limit');
        if (!in_array($limit, [15, 30, 50, 100], true)) {
            $limit = 15;
        }
        $get = new Get(Upload::class);
        $get->setPaginate($page, $limit);
        $get->setWhere($map);
        $get->setOrderBy('id', 'desc');
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->with(['user' => function (Relation $relation) {
                $relation->select(['id', 'username']);
            }]);
        });

        foreach ($data['list'] as &$item) {
            $id = (int)($item['id'] ?? 0);
            $path = $this->inspectUploadPath((string)($item['path'] ?? ''));
            $thumb = $path['safe'] ? $this->inspectUploadPath($this->thumbnailPath($path['url'])) : null;
            $size = $path['exists'] ? @filesize($path['real_path']) : false;
            $previewable = $path['exists'] && @getimagesize($path['real_path']) !== false;

            // Never expose an untrusted database path as an image/link URL. The
            // raw value is deliberately replaced by a validated public URL.
            $item['safe_path'] = $path['safe'];
            $item['protected'] = !$path['safe'];
            $item['exists'] = $path['exists'];
            $item['previewable'] = $previewable;
            $item['size'] = $size === false ? 0 : (int)$size;
            $item['name'] = $path['safe'] ? basename($path['url']) : "受保护文件记录 #{$id}";
            $item['path'] = $path['safe'] ? $path['url'] : null;
            $item['url'] = $path['safe'] && $path['exists'] ? $path['url'] : null;
            $item['copy_url'] = $item['url'];
            $item['download_url'] = $item['url'] ? "/admin/api/file/download?id={$id}" : null;
            $item['thumb_url'] = $thumb && $thumb['safe'] && $thumb['exists'] ? $thumb['url'] : null;
            unset($item['hash']);
        }
        unset($item);
        return $this->json(data: $data);
    }

    /**
     * Read-only impact preview required before an irreversible file delete.
     * Exact structured references are checked; no fuzzy full-database search is
     * performed because that would create both false positives and false safety.
     * @return array
     * @throws JSONException
     */
    public function deleteImpact(): array
    {
        $impact = $this->fileDeleteImpact($this->fileIds($_POST['list'] ?? []));
        unset($impact['_rows'], $impact['_inspections'], $impact['_thumbnail_inspections']);
        return $this->json(data: $impact);
    }

    /**
     * Batch deletion with a reversible quarantine stage.
     *
     * Files are atomically renamed into a non-public quarantine before the DB
     * delete. A transaction failure restores every staged path. Only after the
     * DB commit are quarantine files unlinked; a failed final unlink is reported
     * explicitly and the inaccessible quarantine copy is retained for ops cleanup.
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {
        $requestedIds = $this->fileIds($_POST['list'] ?? []);
        $staged = [];

        try {
            $impact = DB::transaction(function () use ($requestedIds, &$staged): array {
                $impact = $this->fileDeleteImpact($requestedIds, true);
                if (!$impact['can_delete']) {
                    throw new JSONException($this->deleteBlockedMessage($impact));
                }

                $staged = $this->stageForDeletion(
                    $impact['_rows'],
                    $impact['_inspections'],
                    $impact['_thumbnail_inspections']
                );
                $deleted = Upload::query()->whereIn('id', $requestedIds)->delete();
                if ($deleted !== $impact['file_count']) {
                    throw new JSONException('文件记录或业务引用已变化，未执行删除，请重新预览');
                }
                return $impact;
            });
        } catch (\Throwable $throwable) {
            $restoreFailures = $this->restoreStagedFiles($staged);
            if ($restoreFailures > 0) {
                ManageLog::log($this->getManage(), "[文件管理]删除事务失败，{$restoreFailures} 个隔离文件恢复失败");
                throw new JSONException("数据库删除已回滚，但有 {$restoreFailures} 个文件无法从隔离区恢复，请立即联系运维处理");
            }
            if ($throwable instanceof JSONException) {
                throw $throwable;
            }
            throw new JSONException('文件删除失败，数据库与原文件已保持不变');
        }

        $cleanupFailures = $this->purgeStagedFiles($staged);
        $count = (int)$impact['file_count'];
        if ($cleanupFailures > 0) {
            ManageLog::log($this->getManage(), "[文件管理]删除了 {$count} 个文件记录，{$cleanupFailures} 个隔离文件待清理");
            return $this->json(200, "已删除 {$count} 个文件记录；{$cleanupFailures} 个隔离文件清理失败，已保留在非公开隔离区等待运维清理", [
                'count' => $count,
                'cleanup_complete' => false,
                'cleanup_pending_count' => $cleanupFailures,
            ]);
        }

        ManageLog::log($this->getManage(), "[文件管理]安全删除了 {$count} 个文件");
        return $this->json(200, "已删除 {$count} 个文件", [
            'count' => $count,
            'cleanup_complete' => true,
            'cleanup_pending_count' => 0,
        ]);
    }

    /**
     * 修改文件备注
     * @return array
     * @throws JSONException
     */
    public function note(): array
    {
        $id = $this->fileIds([$_POST['id'] ?? null])[0];
        if (isset($_POST['note']) && !is_scalar($_POST['note'])) {
            throw new JSONException('文件备注格式不正确');
        }
        $note = trim((string)($_POST['note'] ?? ''));
        if (mb_strlen($note, 'UTF-8') > 32 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $note)) {
            throw new JSONException('文件备注不能超过 32 个字符且不能包含控制字符');
        }
        $upload = Upload::query()->find($id);
        if (!$upload) {
            throw new JSONException("文件不存在");
        }
        $upload->note = $note !== '' ? $note : null;
        $upload->save();
        ManageLog::log($this->getManage(), "[文件管理]修改了文件(#{$id})备注");
        return $this->json(200, "备注已保存");
    }

    /**
     * 上传文件：按类型自动归类存储 + 同 hash 去重
     * @return array
     * @throws JSONException
     */
    public function upload(): array
    {
        if (!isset($_FILES['file'])) {
            throw new JSONException("请选择文件");
        }
        $ext = strtolower((string)pathinfo((string)$_FILES['file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOW_EXT, true)) {
            throw new JSONException("不支持的文件类型：{$ext}");
        }
        $cat = $this->category($ext);
        $staticPath = "/assets/cache/general/{$cat}/";
        $handle = $this->upload->handle($_FILES['file'], BASE_PATH . $staticPath, self::ALLOW_EXT, 51200); //上限 50MB
        if (!is_array($handle)) {
            throw new JSONException($handle);
        }
        $fileName = $staticPath . $handle['new_name'];

        //同 hash 已存在则复用旧记录、删掉刚上传的重复副本
        if ($exist = $this->upload->get(md5_file(BASE_PATH . $fileName))) {
            $existingPath = $this->inspectUploadPath($exist);
            FileUtil::remove(BASE_PATH . $fileName);
            if (!$existingPath['safe'] || !$existingPath['exists']) {
                throw new JSONException('检测到相同文件的历史记录路径异常，已阻止复用，请先处理异常记录');
            }
            $fileName = $existingPath['url'];
        } else {
            $this->upload->add($fileName, $cat);
        }
        ManageLog::log($this->getManage(), "[文件管理]上传了文件({$fileName})");
        return $this->json(200, "上传成功", ["path" => $fileName]);
    }

    /**
     * 强制下载：只按数据库记录的 id 取文件，避免任意文件读取
     */
    public function download(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $upload = Upload::query()->find($id);
        $path = $upload ? $this->inspectUploadPath((string)$upload->path) : null;
        if (!$path || !$path['safe'] || !$path['exists']) {
            $this->downloadError('文件不存在、已丢失或路径不安全');
        }

        $handle = @fopen($path['real_path'], 'rb');
        if ($handle === false) {
            $this->downloadError('文件暂时无法读取');
        }
        $stat = fstat($handle);
        if (!is_array($stat) || !isset($stat['size'])) {
            fclose($handle);
            $this->downloadError('文件状态异常');
        }

        $name = basename($path['url']);
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'download';
        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header("Content-Disposition: attachment; filename=\"{$fallback}\"; filename*=UTF-8''" . rawurlencode($name));
        header('Content-Length: ' . (int)$stat['size']);
        header('Cache-Control: no-cache');
        fpassthru($handle);
        fclose($handle);
        exit;
    }

    /**
     * @throws JSONException
     */
    private function fileIds(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new JSONException('请选择要删除的文件');
        }
        $ids = [];
        foreach ($raw as $value) {
            if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int)$value < 1) {
                throw new JSONException('文件编号不正确，请刷新页面后重试');
            }
            $ids[(int)$value] = (int)$value;
        }
        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);
        if (count($ids) > self::MAX_DELETE_COUNT) {
            throw new JSONException('单次最多删除 ' . self::MAX_DELETE_COUNT . ' 个文件');
        }
        return $ids;
    }

    /**
     * @throws JSONException
     */
    private function fileDeleteImpact(array $requestedIds, bool $lock = false): array
    {
        $query = Upload::query()->whereIn('id', $requestedIds)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = $query->get(['id', 'path']);
        $foundIds = $rows->pluck('id')->map(static fn($id): int => (int)$id)->all();
        $missingIds = array_values(array_diff($requestedIds, $foundIds));

        $safePaths = [];
        $referencePaths = [];
        $inspections = [];
        $thumbnailInspections = [];
        $protectedIds = [];
        $existingFileCount = 0;
        $missingFileCount = 0;
        $thumbnailCount = 0;
        foreach ($rows as $row) {
            $id = (int)$row->id;
            $inspection = $this->inspectUploadPath((string)$row->path);
            $inspections[$id] = $inspection;
            if (!$inspection['safe']) {
                $protectedIds[] = $id;
                continue;
            }
            $safePaths[$inspection['url']] = $inspection['url'];
            $referencePaths[$inspection['url']] = $inspection['url'];
            if ($inspection['exists']) {
                $existingFileCount++;
            } else {
                $missingFileCount++;
            }
            $thumb = $this->inspectUploadPath($this->thumbnailPath($inspection['url']));
            $thumbnailInspections[$id] = $thumb;
            if ($thumb['safe'] && $thumb['exists']) {
                $thumbnailCount++;
                // A thumbnail is a physical delete target too. Protect it when
                // another record or business field references that exact URL.
                $referencePaths[$thumb['url']] = $thumb['url'];
            }
        }
        $safePaths = array_values($safePaths);
        $referencePaths = array_values($referencePaths);

        $references = $this->structuredReferenceImpact($requestedIds, $referencePaths, $lock);
        $canDelete = $missingIds === []
            && $protectedIds === []
            && $references['reference_count'] === 0;

        return [
            'requested_count' => count($requestedIds),
            'file_count' => $rows->count(),
            'missing_record_count' => count($missingIds),
            'missing_file_count' => $missingFileCount,
            'missing_count' => count($missingIds) + $missingFileCount,
            'existing_file_count' => $existingFileCount,
            'thumbnail_count' => $thumbnailCount,
            'physical_file_count' => $existingFileCount + $thumbnailCount,
            'safe_path_count' => count($safePaths),
            'protected_count' => count($protectedIds),
            'unsafe_path_count' => count($protectedIds),
            'ticket_reference_count' => $references['ticket_reference_count'],
            'ticket_message_reference_count' => $references['ticket_message_reference_count'],
            'system_message_reference_count' => $references['system_message_reference_count'],
            'content_reference_count' => $references['content_reference_count'],
            'upload_path_reference_count' => $references['upload_path_reference_count'],
            'structured_reference_count' => $references['structured_reference_count'],
            'business_reference_count' => $references['reference_count'],
            'reference_count' => $references['reference_count'],
            'reference_breakdown' => $references['reference_breakdown'],
            'reference_scope' => '已精确检查工单凭证ID/路径、工单与系统消息正文中的完整文件URL、其他上传记录同路径、原图及缩略图的业务字段引用。任意自由文本中的间接描述无法可靠自动识别。',
            'can_delete' => $canDelete,
            '_rows' => $rows,
            '_inspections' => $inspections,
            '_thumbnail_inspections' => $thumbnailInspections,
        ];
    }

    private function structuredReferenceImpact(array $ids, array $paths, bool $lock): array
    {
        $ticketIds = [];
        $ticketByUpload = \App\Model\Ticket::query()->whereIn('proof_upload_id', $ids)->orderBy('id');
        if ($lock) {
            $ticketByUpload->lockForUpdate();
        }
        foreach ($ticketByUpload->get(['id']) as $ticket) {
            $ticketIds[(int)$ticket->id] = true;
        }
        if ($paths !== []) {
            $ticketByPath = \App\Model\Ticket::query()->whereIn('proof_path', $paths)->orderBy('id');
            if ($lock) {
                $ticketByPath->lockForUpdate();
            }
            foreach ($ticketByPath->get(['id']) as $ticket) {
                $ticketIds[(int)$ticket->id] = true;
            }
        }

        $sharedUploadCount = 0;
        if ($paths !== []) {
            $sharedQuery = Upload::query()->whereIn('path', $paths)->whereNotIn('id', $ids)->orderBy('id');
            if ($lock) {
                $sharedQuery->lockForUpdate();
            }
            $sharedUploadCount = $sharedQuery->get(['id'])->count();
        }

        $breakdown = [
            'ticket_proof' => count($ticketIds),
            'other_upload_record' => $sharedUploadCount,
        ];
        $structuredCount = 0;
        if ($paths !== []) {
            $specifications = [
                'commodity_cover' => [\App\Model\Commodity::class, 'cover'],
                'category_icon' => [\App\Model\Category::class, 'icon'],
                'user_avatar' => [\App\Model\User::class, 'avatar'],
                'manage_avatar' => [\App\Model\Manage::class, 'avatar'],
                'user_group_icon' => [\App\Model\UserGroup::class, 'icon'],
                'business_level_icon' => [\App\Model\BusinessLevel::class, 'icon'],
                'pay_icon' => [\App\Model\Pay::class, 'icon'],
                'config_value' => [\App\Model\Config::class, 'value'],
            ];
            foreach ($specifications as $key => [$model, $field]) {
                $referenceQuery = $model::query()->whereIn($field, $paths)->orderBy('id');
                if ($lock) {
                    $referenceQuery->lockForUpdate();
                }
                $count = $referenceQuery->get(['id'])->count();
                $breakdown[$key] = $count;
                $structuredCount += $count;
            }
        }

        // These two content fields intentionally embed validated local image
        // URLs instead of upload ids. Query candidates with LIKE, then parse
        // img[src] and require an exact decoded URL match in PHP.
        $ticketMessageCount = $this->contentReferenceCount(\App\Model\TicketMessage::class, $paths, $lock);
        $systemMessageCount = $this->contentReferenceCount(\App\Model\SystemMessage::class, $paths, $lock);
        $contentCount = $ticketMessageCount + $systemMessageCount;
        $breakdown['ticket_message_content'] = $ticketMessageCount;
        $breakdown['system_message_content'] = $systemMessageCount;

        $ticketCount = count($ticketIds);
        return [
            'ticket_reference_count' => $ticketCount,
            'ticket_message_reference_count' => $ticketMessageCount,
            'system_message_reference_count' => $systemMessageCount,
            'content_reference_count' => $contentCount,
            'upload_path_reference_count' => $sharedUploadCount,
            'structured_reference_count' => $structuredCount,
            'reference_count' => $ticketCount + $sharedUploadCount + $structuredCount + $contentCount,
            'reference_breakdown' => $breakdown,
        ];
    }

    /**
     * Count records whose HTML contains one of the exact validated local URLs.
     * @param class-string<\Illuminate\Database\Eloquent\Model> $model
     */
    private function contentReferenceCount(string $model, array $paths, bool $lock): int
    {
        if ($paths === []) {
            return 0;
        }

        $ids = [];
        foreach (array_chunk($paths, 20) as $pathChunk) {
            $pathLookup = array_fill_keys($pathChunk, true);
            $query = $model::query()->where(function (Builder $builder) use ($pathChunk): void {
                foreach ($pathChunk as $path) {
                    $builder->orWhere('content', 'like', '%' . $this->escapeLike((string)$path) . '%');
                }
            });
            if ($lock) {
                $query->lockForUpdate();
            }
            foreach ($query->get(['id', 'content']) as $row) {
                foreach ($this->contentImageSources((string)$row->content) as $source) {
                    if (isset($pathLookup[$source])) {
                        $ids[(string)$row->id] = true;
                        break;
                    }
                }
            }
        }
        return count($ids);
    }

    /** @return string[] */
    private function contentImageSources(string $content): array
    {
        preg_match_all(
            '/<img\b[^>]*\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i',
            $content,
            $matches,
            PREG_SET_ORDER
        );
        $sources = [];
        foreach ($matches as $match) {
            $source = $match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : ($match[3] ?? ''));
            $sources[] = html_entity_decode(trim((string)$source), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $sources;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function deleteBlockedMessage(array $impact): string
    {
        return '已阻止删除：'
            . "文件记录 {$impact['file_count']} 个，不存在记录 {$impact['missing_record_count']} 个，"
            . "安全路径 {$impact['safe_path_count']} 个，受保护路径 {$impact['protected_count']} 个，"
            . "业务引用 {$impact['reference_count']} 条（工单凭证 {$impact['ticket_reference_count']} 条）。"
            . '请先解除可靠识别出的业务引用；危险路径不会被删除。';
    }

    /**
     * @throws JSONException
     */
    private function stageForDeletion(
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
            $current = $this->inspectUploadPath((string)$row->path);
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
            $thumb = $this->inspectUploadPath($this->thumbnailPath($current['url']));
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
            $restoreFailures = $this->restoreStagedFiles($staged);
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

    private function restoreStagedFiles(array $staged): int
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

    private function purgeStagedFiles(array $staged): int
    {
        $failures = 0;
        foreach ($staged as $item) {
            if (is_file($item['target']) && !@unlink($item['target'])) {
                $failures++;
            }
        }
        return $failures;
    }

    private function thumbnailPath(string $path): string
    {
        return dirname($path) . '/thumb/' . basename($path);
    }

    private function inspectUploadPath(string $path): array
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
            if (!$this->pathInsideRoot($real, $root) || !is_file($real)) {
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
        if ($realParent === false || !$this->pathInsideRoot($realParent, $root)) {
            return ['safe' => false, 'exists' => false, 'url' => null, 'real_path' => null, 'identity' => null];
        }
        return ['safe' => true, 'exists' => false, 'url' => $path, 'real_path' => null, 'identity' => null];
    }

    private function pathInsideRoot(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private function downloadError(string $message): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        exit(json_encode(['code' => 0, 'msg' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 根据后缀判断归类目录
     */
    private function category(string $ext): string
    {
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'ico', 'svg'], true)) {
            return 'image';
        }
        if (in_array($ext, ['mp4', 'webm', 'mov', 'mp3'], true)) {
            return 'video';
        }
        if (in_array($ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv'], true)) {
            return 'doc';
        }
        return 'other';
    }
}
