<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Consts\Hook;
use App\Controller\Base\View\User;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Model\Config;
use App\Service\Shop;
use App\Util\Client;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\RuntimeException;
use Kernel\Exception\ViewException;

#[Interceptor([Waf::class, UserVisitor::class])]
class Index extends User
{
    #[Inject]
    private Shop $shop;

    /**
     * @return string
     * @throws RuntimeException
     * @throws ViewException
     * @throws \ReflectionException
     */
    public function index(): string
    {
        if ((int)Config::get("closed") == 1) {
            return $this->theme("店铺正在维护", "CLOSED", "Index/Closed.html");
        }
        $from = (int)$_GET['from'];

        $_GET['cid'] = $_GET['cid'] ?: Config::get("default_category");

        //获取所有分类
        $category = $this->shop->getCategory($this->getUserGroup());
        hook(Hook::USER_API_INDEX_CATEGORY_LIST, $category);

        return $this->theme("购物", "INDEX", "Index/Index.html", [
            'user' => $this->getUser(),
            'from' => $from,
            "categoryId" => $_GET['cid'],
            "category" => $category
        ]);
    }

    /**
     * @return string
     * @throws ViewException
     * @throws \ReflectionException
     */
    public function item(): string
    {
        $item = $this->shop->getItem((int)$_GET['mid'], $this->getUser(), $this->getUserGroup());
        return $this->theme(strip_tags($item['name']), "ITEM", "Index/Item.html", [
            'user' => $this->getUser(),
            'from' => (int)$_GET['from'],
            "commodityId" => $_GET['mid'],
            'item' => $item
        ]);
    }

    /**
     * @return string
     * @throws ViewException
     * @throws \ReflectionException
     */
    public function query(): string
    {
        return $this->theme("订单查询", "QUERY", "Index/Query.html", ['user' => $this->getUser(), 'tradeNo' => (string)$_GET['tradeNo']]);
    }
}