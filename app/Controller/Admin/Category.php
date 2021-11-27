<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;

/**
 * Class Category
 * @package App\Controller\Admin
 */
#[Interceptor(ManageSession::class)]
class Category extends Manage
{
    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function index(): string
    {
        return $this->render("分类管理", "Trade/Category.html");
    }
}