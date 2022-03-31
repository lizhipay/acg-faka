<?php
declare(strict_types=1);

namespace App\Controller\Base\View;

use App\Model\Config;
use App\Util\Client;
use Kernel\Exception\ViewException;
use Kernel\Util\View;

/**
 * Class ManagePlugin
 * @package App\Controller\Base\View
 */
abstract class ManagePlugin extends \App\Controller\Base\Manage
{
    /**
     * @param string|null $title
     * @param string $template
     * @param array $data
     * @return string
     * @throws \Kernel\Exception\ViewException
     */
    public function render(?string $title, string $template, array $data = [], bool $controller = false): string
    {
        try {
            $data['title'] = $title;
            $data['app']['version'] = \config("app")['version'];

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
            $data['manage_view_path'] = BASE_PATH . '/app/View/Admin/';
            $data['_store_initialize'] = file_exists(BASE_PATH . "/kernel/Plugin.php");
            return View::render($template, $data, BASE_PATH . "/app/Plugin/" . ($controller ? \Kernel\Util\Plugin::$currentControllerPluginName : \Kernel\Util\Plugin::$currentPluginName) . "/View");
        } catch (\SmartyException $e) {
            throw new ViewException($e->getMessage());
        }
    }
}