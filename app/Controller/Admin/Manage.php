<?php
declare(strict_types=1);

namespace App\Controller\Admin;


use App\Interceptor\ManageSession;
use App\Interceptor\Super;
use Kernel\Annotation\Interceptor;

#[Interceptor(ManageSession::class)]
class Manage extends \App\Controller\Base\View\Manage
{
    /**
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    public function set(): string
    {
        return $this->render("个人设置", "Manage/Set.html");
    }

    /**
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    #[Interceptor(Super::class)]
    public function index(): string
    {
        return $this->render("管理员", "Manage/Manage.html");
    }
}