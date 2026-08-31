<?php
declare(strict_types=1);

namespace App\Controller\Base\View;

use App\Consts\Render;
use App\Model\Business;
use App\Model\Config;
use App\Util\Client;
use App\Util\Theme;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;
use Kernel\Util\View;

/**
 * Class Manage
 * @package App\Controller\Base\View
 */
abstract class User extends \App\Controller\Base\User
{
    /**
     * @var array|string[]
     */
    protected array $indexTemplateList = [
        'INDEX', 'ITEM', 'QUERY', 'CLOSED'
    ];

    /**
     * config 里站长自填、买家可见的中文文案。这些既不在静态词包里，主题也不会各自记得包 lang()
     * （shop_name/closed_message 连 Cartoon 都没包），所以在渲染出口统一翻译，issue #832。
     * 只列展示文案：service_url、各类开关与路径不在其中。
     * 店名/站点标题若不希望被翻译，在 TranslationBot 的 brand_words 里登记即可。
     */
    private const TRANSLATABLE_CONFIG = ['notice', 'shop_name', 'title', 'closed_message', 'commodity_name'];

    /**
     * 统一翻译 config 里的展示文案（在分站覆盖之后调用，主站/分站都覆盖到）
     * @param array $config
     * @return array
     */
    private function translateConfigText(array $config): array
    {
        foreach (self::TRANSLATABLE_CONFIG as $key) {
            if (!empty($config[$key]) && is_string($config[$key])) {
                $config[$key] = lang($config[$key], "dyn");
            }
        }
        return $config;
    }

    /**
     * @param string $title
     * @param string $template
     * @param array $data
     * @return string
     * @throws ViewException
     * @throws JSONException
     */
    protected function render(string $title, string $template, array $data = []): string
    {
        try {
            //加载helper
            require(BASE_PATH . "/app/View/User/Helper.php");

            //页面标题统一在此翻译，各控制器仍传中文原文
            $data['title'] = lang($title, "tpl");
            $data['app']['version'] = \config("app")['version'];
            $cfg = Config::list();

            foreach ($cfg as $k => $v) {
                $data["config"][$k] = $v;
            }

            $data['config'] = $this->translateConfigText($data['config']);
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
     * @throws JSONException
     * @throws ViewException
     * @throws \ReflectionException
     */
    protected function theme(string $title, string $template, string $default, array $data = []): string
    {
        try {
            //加载helper
            require(BASE_PATH . "/app/View/User/Helper.php");

            //页面标题统一在此翻译，各控制器仍传中文原文
            $data['title'] = lang($title, "tpl");
            $data['app']['version'] = \config("app")['version'];
            $data['favicon'] = "/favicon.ico";

            $cfg = Config::list();

            foreach ($cfg as $k => $v) {
                $data["config"][$k] = $v;
            }

            if (in_array($template, $this->indexTemplateList)) {
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
            } else {
                $centerTheme = $cfg['user_center_theme'] ?? "Cartoon";
                if (Client::isMobile()) {
                    $theme = $cfg['user_center_mobile_theme'] ?? "0";
                    if ($theme === "" || $theme === "0") {
                        $theme = $centerTheme;
                    }
                } else {
                    $theme = $centerTheme;
                }
                $theme = $theme ?: "Cartoon";
            }

            //模板静态路径
            $data['static'] = "/app/View/User/Theme/" . $theme;

            $domain = Client::getDomain();
            $business = Business::query()->where("subdomain", $domain)->first() ?? Business::query()->where("topdomain", $domain)->first();
            if ($business) {
                $data['isBusinessSite'] = true; //分站域名标记，供主题按主站/分站区分展示（如 Seattle 快捷入口）
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

            $data['config'] = $this->translateConfigText($data['config']);

            $defaultThemePath = "User/Theme/Cartoon/";
            $themePath = "User/Theme/{$theme}/";
            $config = Theme::getConfig($theme);
            $path = $defaultThemePath . $default;
            $system = true;

            //判断路径是否存在
            if (!empty($config['theme']) && key_exists($template, $config['theme'])) {
                $path = $themePath . $config['theme'][$template];
                $system = false;
            }

            $user = $this->getUser();
            if ($user) {
                $data['user'] = $user;
                $data['group'] = $this->getUserGroup()?->toArray();
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
