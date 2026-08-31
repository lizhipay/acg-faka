<?php
declare (strict_types=1);

namespace App\Controller\Admin;

use App\Interceptor\ManageSession;
use App\Interceptor\Owner;
use Kernel\Annotation\Interceptor;

#[Interceptor(ManageSession::class)]
class File extends Manage
{
    /**
     * 文件管理页面
     * @return string
     */
    #[Interceptor(Owner::class)]
    public function index(): string
    {
        return $this->render("文件管理", "Manage/File.html");
    }
}
