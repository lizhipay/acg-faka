<?php
declare(strict_types=1);

namespace App\Controller\Admin;


use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;

/**
 * Class Commodity
 * @package App\Controller\Admin
 */
#[Interceptor(ManageSession::class)]
class Commodity extends Manage
{
    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function index(): string
    {
        return $this->render("商品管理", "Trade/Commodity.html");
    }
}