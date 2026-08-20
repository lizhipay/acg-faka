<?php
declare(strict_types=1);

namespace App\Controller\Base\View;


use App\Model\Business;
use App\Model\Config;
use App\Util\Client;
use App\Util\Theme;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;
use Kernel\Util\View;

/**
 * Class UserPlugin
 * @package App\Controller\Base\View
 */
abstract class UserPlugin extends \App\Controller\Base\User
{
    /**
     * @param string|null $title
     * @param string $template
     * @param array $data
     * @param bool $controller
     * @return string
     * @throws ViewException
     * @throws JSONException
     * @throws \ReflectionException
     */
    protected function render(?string $title, string $template, array $data = [], bool $controller = false): string
    {
        try {
            $data['title'] = $title;
            $cfg = Config::list();
            foreach ($cfg as $k => $v) {
                $data["config"][$k] = $v;
            }

            if (Client::isMobile() && $data['config']['background_mobile_url']) {
                $data['config']['background_url'] = $data['config']['background_mobile_url'];
            }

            $domain = Client::getDomain();
            $business = Business::query()->where("subdomain", $domain)->first() ?? Business::query()->where("topdomain", $domain)->first();
            if ($business) {
                $data['config']['shop_name'] = $business->shop_name;
                $data['config']['title'] = $business->title;
                $data['config']['notice'] = $business->notice;
                $data['config']['service_url'] = $business->service_url != "" ? $business->service_url : "https://wpa.qq.com/msgrd?v=1&uin={$business->service_qq}";
            }
            $user = $this->getUser();
            if ($user) {
                $data['user'] = $user;
                //getUserGroup() 声明就是 ?UserGroup：会员等级没覆盖到该用户的累计充值
                //（比如站点压根没配等级、或最低等级门槛大于 0）时返回 null。
                //这里少了个 ?->，一旦为 null 就是 Error，而它是在主题的输出缓冲里
                //抛出来的 —— ob_end_clean() 没机会执行，PHP 结束时把半截缓冲区冲出去，
                //页面看着正常、钩子之后的内容（在线客服按钮就在这儿）全没了。
                //同一处在 View/User.php:146 早就是 ?-> 写法，这里是漏了。见 issue #818
                $data['group'] = $this->getUserGroup()?->toArray();
            }
            $data['setting'] = Theme::getConfig("Cartoon")["setting"];
            $data['default_view_path'] = BASE_PATH . '/app/View/User/Theme/Cartoon/';
            return View::render(
                $template,
                $data,
                BASE_PATH . "/app/Plugin/" . ($controller ? \Kernel\Util\Plugin::$currentControllerPluginName : \Kernel\Util\Plugin::$currentPluginName) . "/View",
                $controller
            );
        } catch (\SmartyException $e) {
            throw new ViewException($e->getMessage());
        }
    }
}