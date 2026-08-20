<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Consts\Hook;
use App\Controller\Base\View\User;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Model\Config;
use App\Service\Shop;
use App\Util\Tree;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
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
     * @throws JSONException
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
        //分类名同样是动态文案，与 API 侧 Api\Index::data() 的处理保持一致
        $category = Tree::generate(\Kernel\Util\Lang::transList($this->shop->getCategory($this->getUserGroup()), ['name']));
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
     * @throws JSONException
     * @throws ViewException
     * @throws \ReflectionException
     */
    public function item(): string
    {
        $item = $this->shop->getItem((int)$_GET['mid'], $this->getUser(), $this->getUserGroup());
        hook(Hook::USER_API_INDEX_COMMODITY_DETAIL_INFO, $item);

        $item['is_stock'] = $item['stock'] > 0;
        if ($item['inventory_hidden'] == 1) {
            //模糊库存文案直接渲染进模板，就地翻译
            $item['stock'] = lang(match (true) {
                $item['stock'] <= 0 => "已售罄",
                $item['stock'] <= 5 => "所剩无几",
                $item['stock'] <= 20 => "数量有限",
                $item['stock'] <= 100 => "现货充足",
                default => "库存爆棚"
            }, "tpl");
        }

        //商品展示文案统一在控制器出口翻译：主题各自记得加 lang() 是靠不住的，
        //19 个主题里只有 Cartoon 加了一部分(issue #832)。字段清单见 CommodityLang。
        $item = \App\Util\CommodityLang::detail($item);

        return $this->theme(strip_tags($item['name']), "ITEM", "Index/Item.html", [
            'user' => $this->getUser(),
            'from' => (int)$_GET['from'],
            "commodityId" => (int)$_GET['mid'],
            'item' => $item
        ]);
    }

    /**
     * @return string
     * @throws JSONException
     * @throws ViewException
     * @throws \ReflectionException
     */
    public function query(): string
    {
        return $this->theme("订单查询", "QUERY", "Index/Query.html", ['user' => $this->getUser(), 'tradeNo' => (string)$_GET['tradeNo']]);
    }
}