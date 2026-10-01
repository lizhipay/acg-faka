<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Consts\Manage as ManageConst;
use App\Controller\Base\View\Manage;
use App\Service\ManageSessionManager;
use App\Service\ManageWebauthnService;
use App\Util\AdminLock;
use App\Util\Client;
use Kernel\Exception\ViewException;

/**
 * Class Authentication
 * @package App\Controller\Admin
 */
class Authentication extends Manage
{

    /**
     * 管理员登录
     * @return string
     * @throws ViewException
     */
    public function login(): string
    {
        if (array_key_exists(ManageConst::SESSION, $_COOKIE) && isset($_COOKIE[ManageConst::SESSION])) {
            Client::redirect("/admin/dashboard/index", "正在登录..", 1);
        }
        return $this->render("登录", "Authentication/Login.html");
    }

    public function logout(): void
    {
        $cookie = (string)($_COOKIE[ManageConst::SESSION] ?? '');
        if ($cookie !== '') {
            ManageSessionManager::revokeEncodedToken($cookie);
        }
        ManageSessionManager::clearCookie();
        Client::redirect("/admin/authentication/login", "注销成功..", 1);
    }

    /**
     * 闲置锁屏页：会话仍有效但已锁定，需重新以密码或 passkey 解锁。
     * @throws ViewException
     */
    public function lock(): string
    {
        $cookie = (string)($_COOKIE[ManageConst::SESSION] ?? '');
        if ($cookie === '') {
            Client::redirect("/admin/authentication/login", "请先登录..", 1);
        }
        $resolved = ManageSessionManager::authenticate($cookie, false);
        if (!$resolved) {
            ManageSessionManager::clearCookie();
            Client::redirect("/admin/authentication/login", "登录会话过期，请重新登录..", 1);
        }
        $manage = $resolved['manage'];
        $goto = $this->safeGoto((string)($_GET['goto'] ?? '/admin/dashboard/index'));

        //未锁定（手动访问或锁屏已关闭）直接回目标页，避免无谓拦人
        if (!AdminLock::isLocked($resolved['session'])) {
            Client::redirect($goto, "", 0);
        }

        return $this->render("锁定", "Authentication/Lock.html", [
            'lockNickname' => (string)($manage->nickname ?: $manage->email),
            'lockAvatar' => (string)($manage->avatar ?: '/favicon.ico'),
            'lockEmail' => (string)$manage->email,
            'lockHasPasskey' => ManageWebauthnService::countForManage($manage) > 0,
            'lockGoto' => $goto,
        ]);
    }

    /**
     * 仅允许站内 /admin 开头的相对路径，防开放重定向 / 头注入。
     */
    private function safeGoto(string $goto): string
    {
        $fallback = "/admin/dashboard/index";
        $goto = trim($goto);
        // 只放行站内 /admin 单斜线相对路径；额外拒绝引号/尖括号，纵深防御——即便日后 goto 流到
        // 未经 ViewSafe 的上下文也无法突破属性/标签造成 XSS。控制字元、反斜线、协议相对一并拒。
        if ($goto === '' || $goto[0] !== '/' || str_starts_with($goto, '//')
            || strpbrk($goto, "\"'<>\\\r\n\t ") !== false) {
            return $fallback;
        }
        $path = parse_url($goto, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/admin')) {
            return $fallback;
        }
        return $goto;
    }
}
