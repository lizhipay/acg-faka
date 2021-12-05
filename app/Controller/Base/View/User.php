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
     * @param array $data
     * @return string
     * @throws ViewException
     */
    public function theme(string $title, string $template, string $default, array $data = []): string
    {
        try {
            $data['title'] = $title;
            $cfg = Config::list();

            foreach ($cfg as $k => $v) {
                $data["config"][$k] = $v;
            }
            $theme = $cfg['user_theme'];

            $domain = Client::getDomain();
            $business = Business::query()->where("subdomain", $domain)->first() ?? Business::query()->where("topdomain", $domain)->first();
            if ($business) {
                $data['config']['shop_name'] = $business->shop_name;
                $data['config']['title'] = $business->title;
                $data['config']['notice'] = $business->notice;
                $data['config']['service_url'] = $business->service_url != "" ? $business->service_url : "https://wpa.qq.com/msgrd?v=1&uin={$business->service_qq}";
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
                $data['user'] = $user->toArray();
                $data['group'] = $this->getUserGroup()->toArray();
            }

            if ($config['info']['RENDER'] == Render::ENGINE_SMARTY || $system) {
                return View::render($path, $data);
            } elseif ($config['info']['RENDER'] == Render::ENGINE_PHP) {
                require(BASE_PATH . '/app/View/' . $path);
                return "";
            }
        } catch (\SmartyException $e) {
            throw new ViewException($e->getMessage());
        }
    }


}