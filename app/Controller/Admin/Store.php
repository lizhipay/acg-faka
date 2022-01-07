<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use App\Util\Client;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

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
     * @throws \Kernel\Exception\JSONException
     */
    public function home(): string
    {

        if (!file_exists(BASE_PATH . "/kernel/Plugin.php")) {
            throw new JSONException("您已离线，无法再使用应用商店。");
        }

        return $this->render("应用商店", "Store/Store.html", ["toolbar" => $this->TOOLBAR]);
    }


    /**
     * @throws \Kernel\Exception\ViewException
     * @throws \Kernel\Exception\JSONException
     */
    public function developer(): string
    {
        if (!file_exists(BASE_PATH . "/kernel/Plugin.php")) {
            throw new JSONException("您已离线，无法再使用应用商店。");
        }

        return $this->render("开发者中心", "Store/Developer.html", ["toolbar" => $this->TOOLBAR]);
    }
}