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
        if (!isset($_FILES['file'])) {
            throw new JSONException("请选择文件");
        }
        $handle = $this->upload->handle($_FILES['file'], BASE_PATH . '/assets/cache/images', ['jpg', 'png', 'jpeg', 'ico', 'gif', 'ico', 'mp4'], 1024000);
        if (!is_array($handle)) {
            throw new JSONException($handle);
        }
        return $this->json(200, '上传成功', ['path' => '/assets/cache/images/' . $handle['new_name']]);
    }
}