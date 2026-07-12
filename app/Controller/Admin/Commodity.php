<?php
declare(strict_types=1);

namespace App\Controller\Admin;


use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\ViewException;

/**
 * Class Commodity
 * @package App\Controller\Admin
 */
#[Interceptor(ManageSession::class)]
class Commodity extends Manage
{
    /**
     * @throws ViewException
     */
    public function index(): string
    {

        //未上架
        $not = \App\Model\Commodity::query()->where("status", 0)->count();
        //已上架
        $shelves = \App\Model\Commodity::query()->where("status", 1)->count();
        //主站商品
        $main = \App\Model\Commodity::query()->where("owner", 0)->count();
        //总商品
        $all = $not + $shelves;
        //子站商品
        $child = $all - $main;
        //子站上架
        $childShelves = \App\Model\Commodity::query()->where("status", 1)->where("owner", "!=", 0)->count();

        $data = [
            'not' => number_format($not),
            'shelves' => number_format($shelves),
            'main' => number_format($main),
            'all' => number_format($all),
            'child' => number_format($child),
            'child_shelves' => number_format($childShelves),
        ];

        return $this->render("商品管理", "Trade/Commodity.html", $data);
    }
}