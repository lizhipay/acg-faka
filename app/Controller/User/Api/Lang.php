<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Util\Client;
use App\Util\Throttle;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, UserVisitor::class], Interceptor::TYPE_API)]
class Lang extends User
{

    /**
     * 当前语言的翻译字典，输出为可执行脚本并允许浏览器强缓存。
     * URL 携带 lang_version，翻译更新即换 URL，无需协商缓存。
     * @return string
     */
    public function dict(): string
    {
        $lang = strtolower(trim((string)($_GET['lang'] ?? "")));
        if (!in_array($lang, \Kernel\Util\Lang::LANGS, true) || $lang === \Kernel\Util\Lang::SOURCE) {
            header("Content-Type: application/javascript; charset=utf-8");
            return "/* unsupported lang */";
        }

        $dict = \Kernel\Util\Lang::dict($lang);

        header("Content-Type: application/javascript; charset=utf-8");
        if ($dict === []) {
            //字典为空说明 runtime/lang 缺失或尚未重建（被清盘、手工删除、刚装完还没 rebuild）。
            //这种降级结果绝不能按 immutable 缓存一年——否则一次短暂的服务端异常，
            //会把"空字典"永久钉进每个访客的浏览器，直到 lang_version 变化才解除。
            header("Cache-Control: no-store");
        } else {
            header("Cache-Control: public, max-age=31536000, immutable");
        }
        return 'setVar("I18N",' . json_encode($dict, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT) . ');';
    }

    /**
     * 前端 i18n() miss 上报：喂入与 PHP 侧同一套 miss 队列(shutdown 批量落库并触发 LANG_MISS)
     * @return array
     * @throws JSONException
     */
    public function report(): array
    {
        //限流：miss 上报是免登录端点，防刷库
        if (Throttle::tooMany("langreport:ip:" . Client::getAddress(), 30, 300)) {
            throw new JSONException("请求过于频繁");
        }

        $list = $this->request->json("list") ?? $this->request->post("list") ?? [];
        if (!is_array($list)) {
            $list = [];
        }

        $count = 0;
        foreach ($list as $item) {
            if (!is_string($item)) {
                continue;
            }
            //collect 内部还有汉字过滤/长度上限/每请求条数上限
            \Kernel\Util\Lang::collect($item, "js");
            if (++$count >= 50) {
                break;
            }
        }

        return $this->json(200, "success", ["count" => $count]);
    }
}
