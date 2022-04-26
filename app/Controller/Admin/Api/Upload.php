<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Util\File;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

/**
 * Class Upload
 * @package App\Controller\Admin\Api
 */
#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Upload extends Manage
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
        if (!isset($_FILES['file'])) {
            throw new JSONException("请选择文件");
        }

        $handle = $this->upload->handle($_FILES['file'], BASE_PATH . '/assets/cache/images', ['jpg', 'png', 'jpeg', 'ico', 'gif', 'mp4', 'zip', 'woff', 'woff2', 'ttf', 'otf'], 1024000);
        if (!is_array($handle)) {
            throw new JSONException($handle);
        }

        return $this->json(200, '上传成功', ['path' => '/assets/cache/images/' . $handle['new_name']]);
    }


    /**
     * 获取图像列表
     * @return array
     */
    public function images(): array
    {
        $page = (int)$_POST['page'];
        $limit = (int)$_POST['limit'];


        $path = BASE_PATH . '/assets/cache/images/';

        $list = (array)scandir($path, SCANDIR_SORT_DESCENDING);
        array_splice($list, -2);

        $ext = ['png', 'jpg', 'jpeg', 'ico'];
        foreach ($list as $index => $val) {
            $exp = explode(".", $val);
            if (!in_array(end($exp), $ext)) {
                unset($list[$index]);
            }
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