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

    /** 会员只能传图片（#1136）：前台只用到头像、商品图和编辑器插图，压缩包/视频/字体不开放给会员 */
    private const MAX_IMAGE_KB = 10240;

    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function send(Request $request): array
    {
        $type = strtolower((string)$request->get("mime"));
        $thumbHeight = (int)$request->get("thumb_height");

        //前端上传地址永远带 ?mime=；到这里为空基本都是服务器环境把 URL 参数吞了（issue #794）
        if ($type === '') {
            throw new JSONException("上传参数丢失：服务器未收到 mime 参数，请检查伪静态规则(try_files 是否带 \$args)、WAF 或 CDN 是否丢弃了链接参数");
        }

        if (!in_array($type, self::MIME)) {
            throw new JSONException("mime not supported");
        }
        if ($type !== self::MIME[0]) {
            throw new JSONException("会员只能上传图片");
        }
        $this->throttle();
        $static_path = "/assets/cache/user/{$this->getUser()->id}/{$type}/";
        $handle = $this->upload->handle($_FILES['file'] ?? null, BASE_PATH . $static_path, \App\Util\UploadGuard::MEMBER_IMAGE_EXTENSIONS, self::MAX_IMAGE_KB);
        if (!is_array($handle)) {
            throw new JSONException($handle);
        }

        $fileName = $static_path . $handle['new_name'];
        $this->sanitize(BASE_PATH . $fileName, (string)$handle['ext']);

        // 去重只在「当前用户自己的上传记录」内进行:
        // 否则同一张图若已被管理员(/general/)或其它用户上传过,全局去重会命中他人记录,
        // 导致本次上传既不落库、文件又被删除,该用户「相册」永远看不到自己传的图。
        if ($tmp = $this->upload->get(md5_file(BASE_PATH . $fileName), $this->getUser()->id)) {
            File::remove(BASE_PATH . $fileName);
            $fileName = $tmp;
        } else {
            //落库失败只可能是撞了全局唯一键(同一张图别人传过)：复用那份文件，别把 500 抛给前端
            $shared = $this->upload->add($fileName, $type, $this->getUser()->id);
            if ($shared !== null && $shared !== $fileName) {
                File::remove(BASE_PATH . $fileName);
                $fileName = $shared;
            }
        }

        $append = [];
        //生成缩略图
        if ($type == self::MIME[0] && $thumbHeight > 0) {
            $imageFile = BASE_PATH . $fileName;
            $thumbUrl = $this->image->createThumbnail($fileName, $thumbHeight);
            if (!$thumbUrl) {
                if (is_file($imageFile)) {
                    //按归属删除：$fileName 经全局去重可能已指向他人文件，只删本人的记录+文件
                    $this->upload->remove($fileName, $this->getUser()->id);
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
        $this->throttle();
        $handle = $this->upload->handle($_FILES['file'], BASE_PATH . '/assets/cache/user/' . $userId . '/images', \App\Util\UploadGuard::MEMBER_IMAGE_EXTENSIONS, self::MAX_IMAGE_KB);
        if (!is_array($handle)) {
            throw new JSONException($handle);
        }
        $path = '/assets/cache/user/' . $userId . '/images/' . $handle['new_name'];
        $this->sanitize(BASE_PATH . $path, (string)$handle['ext']);
        return $this->json(200, '上传成功', ['path' => $path]);
    }

    /**
     * 会员上传频率：每人 10 分钟 60 张、每个 IP 10 分钟 120 张
     * @throws JSONException
     */
    private function throttle(): void
    {
        if (\App\Util\Throttle::tooMany("upload:user:" . $this->getUser()->id, 60, 600)
            || \App\Util\Throttle::tooMany("upload:ip:" . \App\Util\Client::getAddress(), 120, 600)) {
            throw new JSONException("上传太频繁，请稍后再试");
        }
    }

    /**
     * 图片落盘后重新编码，丢掉 EXIF 和附在图片后面的数据；不合格就删掉
     * @throws JSONException
     */
    private function sanitize(string $file, string $extension): void
    {
        $error = \App\Util\UploadGuard::sanitizeImage($file, $extension);
        if ($error !== null) {
            File::remove($file);
            throw new JSONException($error);
        }
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