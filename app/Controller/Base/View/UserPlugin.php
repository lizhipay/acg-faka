<?php
declare(strict_types=1);

namespace App\Controller\Base\View;


use App\Model\Business;
use App\Model\Config;
use App\Util\Client;
use App\Util\Theme;
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
     * @throws \ReflectionException
     */
    public function render(?string $title, string $template, array $data = [], bool $controller = false): string
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
                $data['group'] = $this->getUserGroup()->toArray();
            }
            $data['setting'] = Theme::getConfig("Cartoon")["setting"];
            $data['default_view_path'] = BASE_PATH . '/app/View/User/Theme/Cartoon/';
            return View::render($template, $data, BASE_PATH . "/app/Plugin/" . ($controller ? \Kernel\Util\Plugin::$currentControllerPluginName : \Kernel\Util\Plugin::$currentPluginName) . "/View");
        } catch (\SmartyException $e) {
            throw new ViewException($e->getMessage());
        }
    }
}