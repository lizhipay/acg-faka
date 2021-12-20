<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Consts\Hook;
use App\Controller\Base\View\User;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Model\Config;
use Kernel\Annotation\Interceptor;
use Kernel\Util\Plugin;

#[Interceptor([Waf::class, UserVisitor::class])]
class Index extends User
{
    /**
     * @return string
     * @throws \Kernel\Exception\ViewException|\Kernel\Exception\JSONException
     */
    public function index(): string
    {
        if ((int)Config::get("closed") == 1) {
            return $this->theme("店铺正在维护", "CLOSED", "Index/Closed.html");
        }
        $from = (int)$_GET['from'];

        $map = [];
        parse_str(base64_decode(urldecode((string)$_GET['code'])), $map);

        if ($map['from']) {
            $from = $map['from'];
        }

        $map['a'] = $map['a'] ? $map['a'] : Config::get("default_category");


        return $this->theme("首页", "INDEX", "Index/Index.html", ['user' => $this->getUser(), 'from' => $from, "categoryId" => $map['a'], "commodityId" => $map['b']]);
    }

    /**
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    public function query(): string
    {
        $tradeNo = (string)$_GET['tradeNo'];
        return $this->theme("订单查询", "QUERY", "Index/Query.html", ['user' => $this->getUser(), 'tradeNo' => $tradeNo]);
    }
}