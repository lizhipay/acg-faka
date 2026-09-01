<?php
declare(strict_types=1);

namespace App\Controller\Base\View;

use App\Model\Config;
use App\Util\Client;
use App\Util\ViewSafe;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;
use Kernel\Util\View;

abstract class Manage extends \App\Controller\Base\Manage
{
    protected function render(string $title, string $template, array $data = []): string
    {
        try {
            require(BASE_PATH . "/app/View/Admin/Helper.php");

            $data['title'] = lang($title, "tpl");
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

            $data['_app_store_load_state'] = defined('_APP_STORE_LOAD_STATE') && \_APP_STORE_LOAD_STATE === true;

            return View::render('Admin/' . $template, ViewSafe::escape($data));
        } catch (\SmartyException $e) {
            throw new ViewException($e->getMessage());
        }
    }
}