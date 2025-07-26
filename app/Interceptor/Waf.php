<?php
declare(strict_types=1);

namespace App\Interceptor;


use Kernel\Annotation\InterceptorInterface;
use Kernel\Exception\JSONException;
use Kernel\Util\View;
use Kernel\Waf\Firewall;

class Waf implements InterceptorInterface
{


    /**
     * @param int $type
     * @return void
     * @throws JSONException
     * @throws \SmartyException
     */
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
    }
}