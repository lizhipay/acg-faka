<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Entity\Query\Get;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Image;
use App\Service\Query;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Util\File;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class Upload extends User
{
    #[Inject]
    private \App\Service\Upload $upload;

    #[Inject]
    private Query $query;

    #[Inject]
    private Image $image;
    const MIME = ['image', 'video', 'doc', 'other'];

    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function send(Request $request): array
    {
        $type = strtolower((string)$request->get("mime"));
        $thumbHeight = (int)$request->get("thumb_height");

        if (!in_array($type, self::MIME)) {
            throw new JSONException("mime not supported");
        }
        $static_path = "/assets/cache/user/{$this->getUser()->id}/{$type}/";
        $handle = $this->upload->handle($_FILES['file'], BASE_PATH . $static_path, ['jpg', 'png', 'jpeg', 'bmp', 'webp', 'ico', 'gif', 'mp4', 'zip', 'woff', 'woff2', 'ttf', 'otf'], 1024000);
        if (!is_array($handle)) {
            throw new JSONException($handle);
        }

        $fileName = $static_path . $handle['new_name'];

        if ($tmp = $this->upload->get(md5_file(BASE_PATH . $fileName))) {
            File::remove(BASE_PATH . $fileName);
            $fileName = $tmp;
        } else {
            $this->upload->add($fileName, $type, $this->getUser()->id);
        }

        $append = [];
        //生成缩略图
        if ($type == self::MIME[0] && $thumbHeight > 0) {
            $imageFile = BASE_PATH . $fileName;
            $thumbUrl = $this->image->createThumbnail($fileName, $thumbHeight);
            if (!$thumbUrl) {
                if (is_file($imageFile)) {
                    $this->upload->remove($fileName);
                }
                throw new JSONException("图片上传失败，原因：生成缩略图失败");
            }
            $append['thumb_url'] = $thumbUrl;
        }

        return $this->json(200, '上传成功', ["url" => $fileName, "append" => $append]);
    }

    /**
     * @return array
     */
    public function get(): array
    {
        $get = new Get(\App\Model\Upload::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setOrderBy('id');
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->where("user_id", $this->getUser()->id);
        });
        foreach ($data['list'] as &$item) {
            $baseImagePathInfo = pathinfo($item['path']);
            $thumbPath = $baseImagePathInfo['dirname'] . '/thumb/' . $baseImagePathInfo['basename'];
            if (is_file(BASE_PATH . $thumbPath)) {
                $item['thumb_url'] = $thumbPath;
            }
        }

        return $this->json(data: $data);
    }

    /**
     * 文件上传
     * @return array
     * @throws JSONException
     */
    public function handle(): array
    {
        $userId = $this->getUser()->id;

        if (!isset($_FILES['file'])) {
            throw new JSONException("请选择文件");
        }
        $handle = $this->upload->handle($_FILES['file'], BASE_PATH . '/assets/cache/user/' . $userId . '/images', ['jpg', 'png', 'jpeg', 'ico', 'gif', 'ico', 'mp4', 'woff', 'woff2', 'ttf', 'otf'], 1024000);
        if (!is_array($handle)) {
            throw new JSONException($handle);
        }
        return $this->json(200, '上传成功', ['path' => '/assets/cache/user/' . $userId . '/images/' . $handle['new_name']]);
    }

    /**
     * 获取图像列表
     * @return array
     */
    public function images(): array
    {
        $page = (int)$_POST['page'];
        $limit = (int)$_POST['limit'];
        $userId = $this->getUser()->id;

        $path = BASE_PATH . '/assets/cache/user/' . $userId . '/images/';

        $list = (array)scandir($path, SCANDIR_SORT_DESCENDING);
        array_splice($list, -2);

        $ext = ['png', 'jpg', 'jpeg', 'ico'];
        foreach ($list as $index => $val) {
            $exp = explode(".", $val);
            if (!in_array(end($exp), $ext)) {
                unset($list[$index]);
                continue;
            }

            $list[$index] = '/assets/cache/user/' . $userId . '/images/' . $val;
        }

        $list = array_values($list);
        $count = count($list);
        $offset = ($page - 1) * $limit;
        $data = array_slice($list, $offset, $limit);
        $json = $this->json(200, "success", $data);
        $json['count'] = $count;
        return $json;
    }
}