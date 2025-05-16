<?php
declare (strict_types=1);

namespace Kernel\Util;


class View
{
    /**
     * @param string $template
     * @param array $data
     * @param string $dir
     * @param bool $controller
     * @return string
     * @throws \SmartyException
     */
    public static function render(string $template, array $data = [], string $dir = BASE_PATH . '/app/View', bool $controller = true): string
    {
        $engine = new \Smarty();
        $engine->setTemplateDir($dir);
        $engine->setCacheDir(BASE_PATH . '/runtime/view/cache');
        $engine->setCompileDir(BASE_PATH . '/runtime/view/compile');
        $engine->left_delimiter = '#{';
        $engine->right_delimiter = '}';
        foreach ($data as $key => $item) {
            $engine->assign($key, $item);
        }
        $result = $engine->fetch($template);
        $controller && hook(\App\Consts\Hook::RENDER_VIEW, $result);
        return $result;
    }
}