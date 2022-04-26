<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class Upload extends User
{
    #[Inject]
    private \App\Service\Upload $upload;

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