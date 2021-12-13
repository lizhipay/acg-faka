<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;

#[Interceptor(ManageSession::class)]
class Store extends Manage
{

    private array $TOOLBAR = [
        ["name" => '<i class="fab fa-app-store"></i> 插件市场', "url" => "/admin/store/home"],
        ["name" => '<i class="fas fa-laptop-code"></i> 开发者中心', "url" => "/admin/store/developer"]
    ];

    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function index(): string
    {
        return $this->render("店铺共享", "Shared/Store.html");
    }


    /**
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    public function home(): string
    {
        return $this->render("应用商店", "Store/Store.html", ["toolbar" => $this->TOOLBAR]);
    }


    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function developer(): string
    {
        return $this->render("开发者中心", "Store/Developer.html", ["toolbar" => $this->TOOLBAR]);
    }
}