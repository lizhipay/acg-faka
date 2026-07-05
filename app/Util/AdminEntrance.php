<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Config;
use Kernel\Util\Session;

/**
 * 后台安全入口（由 Entrance 插件下沉到系统核心，不再依赖插件）。
 *
 * 访客必须先访问"安全入口地址"（如 /aq1314）完成放行，才能进入 /admin；
 * 可选 IP 白名单。未配置安全入口地址时不启用（默认关闭，安全）。
 *
 * 配置项（网站设置-基本设置，位于"关闭请求日志"下方）：
 *   admin_entrance  安全入口地址（如 /aq1314，留空则不启用）
 */
class AdminEntrance
{
    private const SESSION = "entrance_status";

    /**
     * 在内核初始化阶段调用，先于后台路由生效。
     */
    public static function guard(): void
    {
        $location = strtolower(trim(trim((string)Config::get("admin_entrance")), "/"));
        if ($location === "") {
            return; //未配置安全入口 → 不启用
        }

        $segments = explode("/", trim((string)($_GET['s'] ?? ''), "/"));
        $route = strtolower((string)($segments[0] ?? ''));

        //命中安全入口 → 放行会话并跳转后台
        if ($route === $location) {
            Session::set(self::SESSION, true);
            Client::redirect("/admin", "认证成功，正在进入后台...", 1);
            return; //Client::redirect 内部已 exit，这里仅为语义完整
        }

        //访问后台但未经安全入口 → 直接 404，不暴露后台入口的存在
        if ($route === "admin" && Session::get(self::SESSION) !== true) {
            self::deny();
        }
    }

    /**
     * 输出通用 404 并终止，不泄露"后台存在/入口机制"等信息。
     */
    private static function deny(): void
    {
        if (!headers_sent()) {
            http_response_code(404);
            header("Content-type: text/html; charset=utf-8");
        }
        exit('<!doctype html><meta charset="utf-8"><title>404 Not Found</title><body style="font-family:sans-serif;text-align:center;padding-top:12%"><h1 style="font-size:52px;margin:0">404</h1><p>Not Found</p></body>');
    }
}
