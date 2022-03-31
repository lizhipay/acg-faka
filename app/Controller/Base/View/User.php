<?php
declare(strict_types=1);

namespace App\Controller\Base\View;

use App\Consts\Render;
use App\Model\Business;
use App\Model\Config;
use App\Util\Client;
use App\Util\Theme;
use Kernel\Exception\ViewException;
use Kernel\Util\View;

/**
 * Class Manage
 * @package App\Controller\Base\View
 */
abstract class User extends \App\Controller\Base\User
{
    /**
     * @param string $title
     * @param string $template
     * @param array $data
     * @return string
     * @throws ViewException
     */
    public function render(string $title, string $template, array $data = []): string
    {
        try {
            $data['title'] = $title;
            $data['app']['version'] = \config("app")['version'];
            $cfg = Config::list();

            foreach ($cfg as $k => $v) {
                $data["config"][$k] = $v;
            }
            return View::render('User/' . $template, $data);
        } catch (\SmartyException $e) {
            throw new ViewException($e->getMessage());
        }
    }

    /**
     * @param string $title
     * @param string $template
     * @param string $default
     * @param array $data
     * @return string
     * @throws ViewException
     * @throws \ReflectionException
     */
    public function theme(string $title, string $template, string $default, array $data = []): string
    {
        try {
            $data['title'] = $title;
            $data['app']['version'] = \config("app")['version'];
            $data['favicon'] = "/favicon.ico";

            $cfg = Config::list();

            foreach ($cfg as $k => $v) {
                $data["config"][$k] = $v;
            }

            if (Client::isMobile()) {
                $theme = $cfg['user_mobile_theme'];
                if ($data['config']['background_mobile_url']) {
                    $data['config']['background_url'] = $data['config']['background_mobile_url'];
                }

            } else {
                $theme = $cfg['user_theme'];
            }


            if ($theme == "0") {
                $theme = $cfg['user_theme'];
            }
            //模板静态路径
            $data['static'] = "/app/View/User/Theme/" . $theme;

            $domain = Client::getDomain();
            $business = Business::query()->where("subdomain", $domain)->first() ?? Business::query()->where("topdomain", $domain)->first();
            if ($business) {
                $data['config']['shop_name'] = $business->shop_name;
                $data['config']['title'] = $business->title;
                $data['config']['notice'] = $business->notice;
                $data['config']['service_url'] = $business->service_url != "" ? $business->service_url : "https://wpa.qq.com/msgrd?v=1&uin={$business->service_qq}";
                if (!$data['from']) {
                    $data['from'] = $business->user_id;
                }
                $businessUser = $business->user;

                if ($businessUser && $businessUser->avatar) {
                    $data['favicon'] = $businessUser->avatar;
                }
            }

            $defaultThemePath = "User/Theme/Cartoon/";
            $themePath = "User/Theme/{$theme}/";
            $config = Theme::getConfig($theme);
            $path = $defaultThemePath . $default;
            $system = true;

            //判断路径是否存在
            if (key_exists($template, $config['theme'])) {
                $path = $themePath . $config['theme'][$template];
                $system = false;
            }

            $user = $this->getUser();
            if ($user) {
                $data['user'] = $user;
                $data['group'] = $this->getUserGroup()->toArray();
            }

            if ($system) {
                $data['setting'] = Theme::getConfig("Cartoon")["setting"];
            } else {
                $data['setting'] = $config['setting'];
            }

            if ($config['info']['RENDER'] == Render::ENGINE_SMARTY || $system) {
                return View::render($path, $data);
            } elseif ($config['info']['RENDER'] == Render::ENGINE_PHP) {
                ob_start();
                require(BASE_PATH . '/app/View/' . $path);
                $result = ob_get_contents();
                ob_end_clean();
                hook(\App\Consts\Hook::RENDER_VIEW, $result);
                return $result;
            }
        } catch (\SmartyException $e) {
            throw new ViewException($e->getMessage());
        }

        return "";
    }


}