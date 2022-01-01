<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Controller\Base\View\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Model\Config;
use App\Model\UserGroup;
use App\Model\UserRecharge;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Util\View;

#[Interceptor(Waf::class, Interceptor::TYPE_VIEW)]
class Recharge extends User
{
    /**
     * @return mixed
     * @throws \Kernel\Exception\ViewException
     */
    #[Interceptor(UserSession::class)]
    public function index(): string
    {

        $rechargeWelfareConfig = explode(PHP_EOL, (string)Config::get("recharge_welfare_config"));

        $welfareConfig = [];

        foreach ($rechargeWelfareConfig as $item) {
            $ape = explode("-", trim($item));
            $welfareConfig[] = [
                "recharge" => $ape[0],
                "amount" => $ape[1]
            ];
        }

        return $this->theme("充值中心", "RECHARGE", "User/Recharge.html", [
            "welfareConfig" => $welfareConfig,
            'groupNext' => UserGroup::get($this->getUser()->recharge, true),
            "groups" => UserGroup::query()->orderBy("recharge", "asc")->get()
        ]);
    }

    /**
     * @throws \Kernel\Exception\JSONException
     * @throws \Kernel\Exception\ViewException
     */
    public function order(): string
    {
        $obj = [];
        parse_str(base64_decode(urldecode((string)$_GET['_PARAMETER'][0])), $obj);
        //获取订单信息
        $order = UserRecharge::query()->where("trade_no", $obj['tradeNo'])->first();
        if (!$order) {
            return '订单不存在';
        }
        $type = (int)$obj['type'];
        $data = (array)json_decode((string)$order->option, true);

        if ($type == 2) {
            if (!$data) {
                throw new JSONException("参数错误");
            }
            return $this->render("正在下单，请稍后..", "Submit.html", [
                "url" => $order->pay_url,
                "data" => $data
            ]);
        }
        return View::render($obj['handle'] . '/View/' . $obj['code'] . '.html', ['order' => $order, 'option' => $data], BASE_PATH . '/app/Pay/');
    }
}