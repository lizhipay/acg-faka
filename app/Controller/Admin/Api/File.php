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
        $get = new Get(Upload::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($_POST);
        $get->setOrderBy('id', 'desc');
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->with(['user' => function (Relation $relation) {
                $relation->select(['id', 'username']);
            }]);
        });

        foreach ($data['list'] as &$item) {
            $full = BASE_PATH . $item['path'];
            $item['exists'] = is_file($full);
            $item['size'] = $item['exists'] ? filesize($full) : 0;
            $info = pathinfo((string)$item['path']);
            $thumb = ($info['dirname'] ?? '') . '/thumb/' . ($info['basename'] ?? '');
            $item['thumb_url'] = is_file(BASE_PATH . $thumb) ? $thumb : null;
        }
        return $this->json(data: $data);
    }

    /**
     * 批量删除：物理文件 + 缩略图 + 数据库记录
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {
        $list = array_values(array_filter(array_map('intval', (array)($_POST['list'] ?? []))));
        if (empty($list)) {
            throw new JSONException("请选择要删除的文件");
        }
        $rows = Upload::query()->whereIn('id', $list)->get();
        $count = 0;
        foreach ($rows as $row) {
            $full = BASE_PATH . $row->path;
            if (is_file($full)) {
                @unlink($full);
            }
            //连带删除缩略图
            $info = pathinfo((string)$row->path);
            $thumb = BASE_PATH . ($info['dirname'] ?? '') . '/thumb/' . ($info['basename'] ?? '');
            if (is_file($thumb)) {
                @unlink($thumb);
            }
            $row->delete();
            $count++;
        }
        ManageLog::log($this->getManage(), "[文件管理]删除了 {$count} 个文件");
        return $this->json(200, "已删除 {$count} 个文件");
    }

    /**
     * 修改文件备注
     * @return array
     * @throws JSONException
     */
    public function note(): array
    {
        $id = (int)($_POST['id'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        if (mb_strlen($note) > 32) {
            $note = mb_substr($note, 0, 32);
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
            FileUtil::remove(BASE_PATH . $fileName);
            $fileName = $exist;
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
        if (!$upload || !is_file(BASE_PATH . $upload->path)) {
            header('content-type:application/json;charset=utf-8');
            exit(json_encode(["code" => 0, "msg" => "文件不存在或已丢失"], JSON_UNESCAPED_UNICODE));
        }
        $full = BASE_PATH . $upload->path;
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename((string)$upload->path) . '"');
        header('Content-Length: ' . filesize($full));
        header('Cache-Control: no-cache');
        readfile($full);
        exit;
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
