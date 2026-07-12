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

        $u = $this->getUser();
        $next = UserGroup::get((float)$u->recharge, true);
        // 进度条口径 = 当前元气 / 下一级门槛(绝对,与「还需 ¥Y」一致:填充+剩余=100%);满级=100。
        // 不用「本级门槛→下级门槛」区间口径 —— 那样元气正好卡在等级线上时会归零、看着「不动」。
        $progress = 100.0;
        if ($next && (float)$next->recharge > 0) {
            $progress = min(100.0, max(0.0, (float)$u->recharge / (float)$next->recharge * 100.0));
        }

        // 金额快选预设:必须尊重后台下限(recharge_min,0=按10)/上限(recharge_max,0=不限,且仅当 > 下限时生效,同 Bind/Recharge.php)
        $rechargeMin = (float)Config::get("recharge_min");
        $rechargeMin = $rechargeMin == 0 ? 10 : $rechargeMin;
        $rechargeMax = (float)Config::get("recharge_max");
        $hasMax = $rechargeMax > 0 && $rechargeMax > $rechargeMin;
        $presets = [$rechargeMin];                                  // 首档恒为下限(必合法、默认选中)
        foreach ([10, 50, 100, 200, 500, 1000, 2000] as $c) {
            if ($c > $rechargeMin && (!$hasMax || $c < $rechargeMax)) {
                $presets[] = (float)$c;
            }
        }
        if ($hasMax) {
            $presets[] = $rechargeMax;                              // 上限也给一档
        }
        $presets = array_slice(array_values(array_unique($presets)), 0, 6);
        $presets = array_map(fn($p) => $p == floor($p) ? (int)$p : $p, $presets);   // 整数去掉 .0

        return $this->theme("充值中心", "RECHARGE", "User/Recharge.html", [
            "welfareConfig" => $welfareConfig,
            'groupNext' => $next,
            "progress" => round($progress),
            "presets" => $presets,
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