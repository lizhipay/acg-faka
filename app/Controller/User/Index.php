<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Controller\Base\View\User;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Model\Config;
use App\Util\Client;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserVisitor::class])]
class Index extends User
{
    /**
     * @return string
     * @throws \Kernel\Exception\ViewException|\Kernel\Exception\JSONException|\ReflectionException
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
     * @throws \Kernel\Exception\ViewException|\ReflectionException
     */
    public function query(): string
    {
        $tradeNo = (string)$_GET['tradeNo'];
        $user = $this->getUser();

        if ($user) {
            Client::redirect("/user/personal/purchaseRecord" . ($tradeNo != "" ? "?tradeNo=" . $tradeNo : ""), "正在跳转..", 0);
        }

        return $this->theme("订单查询", "QUERY", "Index/Query.html", ['user' => $user, 'tradeNo' => $tradeNo]);
    }
}