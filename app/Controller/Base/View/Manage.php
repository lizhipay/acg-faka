<?php
declare(strict_types=1);

namespace App\Controller\Base\View;


use App\Model\Config;
use App\Util\Client;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;
use Kernel\Util\View;

/**
 * Class Manage
 * @package App\Controller\Base\View
 */
abstract class Manage extends \App\Controller\Base\Manage
{
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
            require(BASE_PATH . "/app/View/Admin/Helper.php");

            $data['title'] = $title;
            $data['app']['version'] = \config("app")['version'];
            $data['app']['server'] = (int)\config("store")['server'];

            $cfg = Config::list();

            foreach ($cfg as $k => $v) {
                $data["config"][$k] = $v;
            }

            if (Client::isMobile() && $data['config']['background_mobile_url']) {
                $data['config']['background_url'] = $data['config']['background_mobile_url'];
            }

            $manage = $this->getManage();

            if ($manage) {
                $data["user"] = $this->getManage()->toArray();
                $data['user']['type_text'] = match ($data['user']['type']) {
                    0 => "SYSTEM",
                    1 => "超级管理员",
                    2 => "白班",
                    3 => "夜班"
                };
            }

            $data['_store_initialize'] = file_exists(BASE_PATH . "/kernel/Plugin.php");
            // 加密授权文件是否“成功加载”（其顶层定义了全局常量 _APP_STORE_LOAD_STATE）；比 file_exists 更严格：
            // 文件被删/损坏/篡改导致加载失败时为 false（此时隐藏应用商店/用户信息/通用插件等入口）。
            $data['_app_store_load_state'] = defined('_APP_STORE_LOAD_STATE') && \_APP_STORE_LOAD_STATE === true;

            return View::render('Admin/' . $template, $data);
        } catch (\SmartyException $e) {
            throw new ViewException($e->getMessage());
        }
    }
}