<?php
declare(strict_types=1);

namespace App\Interceptor;

use Kernel\Annotation\Inject;
use Kernel\Annotation\InterceptorInterface;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Util\View;
use App\Util\LinkDomainGuard;
use Kernel\Waf\Firewall;
use Kernel\Waf\URISchemeFilter;

class Waf implements InterceptorInterface
{
    #[Inject]
    private Request $request;

    public function handle(int $type): void
    {
        if (!file_exists(BASE_PATH . '/kernel/Install/Lock')) {
            echo View::render("Rewrite.html");
            exit;
        }

        Firewall::inst()->check(function (array $message) {
            hook(\App\Consts\Hook::WAF_INTERCEPT, $message);
            throw new JSONException("The current session is not secure. Please refresh the web page and try again.");
        });

        if (LinkDomainGuard::enabled()) {
            URISchemeFilter::reset();
            $firewall = Firewall::inst();
            $firewall->xssKiller($this->request->unsafePost());
            $firewall->xssKiller($this->request->unsafeGet());

            $blocked = URISchemeFilter::blocked();
            if ($blocked !== null) {
                $wafMessage = ['link_domain', $blocked];
                hook(\App\Consts\Hook::WAF_INTERCEPT, $wafMessage);
                throw new JSONException("提交内容包含未授权的外链域名：{$blocked}，已被拦截。如确需使用，请联系站长将其加入「安全设置 → 外链域名白名单」。");
            }
        }
    }
}