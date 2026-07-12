<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use App\Util\Client;
use App\Util\Theme;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Exception\ViewException;

#[Interceptor(ManageSession::class)]
class Config extends Manage
{

    private array $TOOLBAR = [
        ["name" => '🤡 基本设置', "url" => "/admin/config/index"],
        ["name" => "👹 短信设置", "url" => "/admin/config/sms"],
        ["name" => "👺 邮箱设置", "url" => "/admin/config/email"],
        ["name" => "🛡️ 其他设置", "url" => "/admin/config/other"],
    ];

    /**
     * Config constructor.
     */
    public function __construct()
    {
        $this->TOOLBAR = array_merge($this->TOOLBAR, (array)hook(\App\Consts\Hook::ADMIN_VIEW_CONFIG_TOOLBAR));
    }

    /**
     * @return string
     * @throws ViewException
     * @throws JSONException
     */
    public function index(): string
    {

        $modes = [
            'REMOTE_ADDR',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'HTTP_CF_CONNECTING_IP'
        ];

        for ($i = 0; $i <= 8; $i++) {
            $ip = Client::getIp($i);
            $modes[$i] = $modes[$i] . " - " . ($ip ?: "此模式不适用");
        }

        $themes = Theme::getThemes();
        $cacheFile = BASE_PATH . "/runtime/plugin/store.cache";

        if (file_exists($cacheFile)) {
            $appStore = (array)json_decode((string)file_get_contents($cacheFile), true) ?: [];
            foreach ($themes as &$theme) {
                $key = $theme['info']['KEY'];

                if (isset($appStore[$key])) {
                    $plugin = $appStore[$key];
                    if ($theme['info']['VERSION'] !== $plugin['version']) {
                        $theme['have_update'] = true;
                        $theme['update_content'] = $plugin['update_content'];
                        $theme['update_version'] = $plugin['version'];
                    }
                }
            }
        }

        return $this->render("网站设置", "Config/Setting.html", [
            "toolbar" => $this->TOOLBAR,
            "themes" => $themes,
            "user_center_mobile_theme" => \App\Model\Config::get("user_center_mobile_theme") ?: "0",
            "ip_get_mode" => $modes,
            "ip_mode" => Client::getClientMode()
        ]);
    }

    /**
     * @return string
     * @throws ViewException
     * @throws RuntimeException
     */
    public function sms(): string
    {
        $smsConfig = json_decode(\App\Model\Config::get("sms_config"), true);
        return $this->render("短信设置", "Config/Sms.html", ["toolbar" => $this->TOOLBAR, "sms" => $smsConfig]);
    }

    /**
     * @return string
     * @throws ViewException
     * @throws RuntimeException
     */
    public function email(): string
    {
        $emailConfig = json_decode(\App\Model\Config::get("email_config"), true);
        return $this->render("邮箱设置", "Config/Email.html", ["toolbar" => $this->TOOLBAR, "email" => $emailConfig]);
    }

    /**
     * @return string
     * @throws ViewException
     */
    public function other(): string
    {
        $category = \App\Model\Category::query()->where("status", 1)->where("owner", 0)->get();
        return $this->render("其他设置", "Config/Other.html", ["toolbar" => $this->TOOLBAR, "category" => $category->toArray()]);
    }
}
