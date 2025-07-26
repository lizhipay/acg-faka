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
use Kernel\Exception\ViewException;
use Kernel\Util\View;

#[Interceptor(Waf::class, Interceptor::TYPE_VIEW)]
class Recharge extends User
{
    /**
     * @return mixed
     * @throws JSONException
     * @throws ViewException
     * @throws \ReflectionException
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
     * @return string
     * @throws JSONException
     * @throws ViewException
     * @throws \SmartyException
     */
    public function order(): string
    {
        if (!isset($_GET['_PARAMETER'][0]) || !isset($_GET['_PARAMETER'][1])) {
            return '订单不存在';
        }

        $tradeNo = $_GET['_PARAMETER'][0];
        $type = (int)$_GET['_PARAMETER'][1];


        $order = UserRecharge::with(["pay"])->where("trade_no", $tradeNo)->first();
        if (!$order) {
            return '订单不存在';
        }

        if (!$order->pay) {
            return '支付方式不存在';
        }


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

        $html = "{$order->pay->handle}/View/{$order->pay->code}.html";

        if (!is_file(BASE_PATH . '/app/Pay/' . $html)) {
            throw new JSONException("视图不存在");
        }

        return View::render($html, ['order' => $order, 'option' => $data], BASE_PATH . '/app/Pay/');
    }
}