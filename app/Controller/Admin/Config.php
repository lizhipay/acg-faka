<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use App\Util\CallbackIpWhitelist;
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
        ["name" => "🔐 安全设置", "url" => "/admin/config/security"],
    ];

    public function __construct()
    {
        $this->TOOLBAR = array_merge($this->TOOLBAR, (array)hook(\App\Consts\Hook::ADMIN_VIEW_CONFIG_TOOLBAR));
    }

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

                    if (!empty($plugin['icon'])) {
                        $theme['icon'] = \App\Service\App::APP_URL . '/' . ltrim((string)$plugin['icon'], '/');
                    }
                    if ($theme['info']['VERSION'] !== $plugin['version']) {
                        $theme['have_update'] = true;
                        $theme['update_content'] = $plugin['update_content'];
                        $theme['update_version'] = $plugin['version'];

                        $theme['plugin_id'] = $plugin['id'] ?? 0;
                        $theme['plugin_type'] = $plugin['type'] ?? 2;
                    }
                }
            }
        }

        $themesJson = json_encode(
            $themes,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return $this->render("网站设置", "Config/Setting.html", [
            "toolbar" => $this->TOOLBAR,
            "themes" => $themes,
            "themes_json" => is_string($themesJson) ? $themesJson : '[]',
            "user_center_mobile_theme" => \App\Model\Config::get("user_center_mobile_theme") ?: "0",
        ]);
    }

    public function sms(): string
    {
        $smsConfig = json_decode(\App\Model\Config::get("sms_config"), true);
        $smsConfig = is_array($smsConfig) ? $smsConfig : [];
        foreach (['accessKeyId', 'accessKeySecret', 'tencentSecretId', 'tencentSecretKey', 'dxbao_password'] as $key) {
            unset($smsConfig[$key]);
        }
        return $this->render("短信设置", "Config/Sms.html", ["toolbar" => $this->TOOLBAR, "sms" => $smsConfig]);
    }

    public function email(): string
    {
        $emailConfig = json_decode(\App\Model\Config::get("email_config"), true);
        $emailConfig = is_array($emailConfig) ? $emailConfig : [];
        unset($emailConfig['password']);
        return $this->render("邮箱设置", "Config/Email.html", ["toolbar" => $this->TOOLBAR, "email" => $emailConfig]);
    }

    public function security(): string
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

        return $this->render("安全设置", "Config/Security.html", [
            "toolbar" => $this->TOOLBAR,
            "ip_get_mode" => $modes,
            "ip_mode" => Client::getClientMode(),
            "trusted_proxy_ips" => Client::getTrustedProxyConfig(),
            "link_domain_auto" => implode('、', \App\Util\LinkDomainGuard::allowList()),
            "admin_entrance" => (string)\App\Model\Config::get('admin_entrance'),
            "request_log_key" => \App\Util\RequestLogCrypto::keyB64(),
            "request_log_summary" => \Kernel\Util\RequestLogger::summary(),
            "csp_summary" => \App\Util\Csp::summary(),
            "csp_violations" => \App\Util\Csp::violations(30),
        ]);
    }

    public function other(): string
    {
        $category = \App\Model\Category::query()->where("status", 1)->where("owner", 0)->get();
        return $this->render("其他设置", "Config/Other.html", [
            "toolbar" => $this->TOOLBAR,
            "category" => $category->toArray(),
            "config" => [
                CallbackIpWhitelist::ENABLED_CONFIG => \App\Model\Config::get(CallbackIpWhitelist::ENABLED_CONFIG),
                CallbackIpWhitelist::RULES_CONFIG => \App\Model\Config::get(CallbackIpWhitelist::RULES_CONFIG),
            ],
        ]);
    }
}
