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

        $data = [];
        //未上架
        $data['not'] = \App\Model\Commodity::query()->where("status", 0)->count();
        //已上架
        $data['shelves'] = \App\Model\Commodity::query()->where("status", 1)->count();
        //主站商品
        $data['main'] = \App\Model\Commodity::query()->where("owner", 0)->count();
        //总商品
        $data['all'] = $data['not'] + $data['shelves'];
        //子站商品
        $data['child'] = $data['all'] - $data['main'];
        //子站上架
        $data['child_shelves'] = \App\Model\Commodity::query()->where("status", 1)->where("owner", "!=", 0)->count();


        return $this->render("商品管理", "Trade/Commodity.html", $data);
    }
}