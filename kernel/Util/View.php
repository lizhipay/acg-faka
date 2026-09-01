<?php
declare (strict_types=1);

namespace Kernel\Util;

use Kernel\Exception\JSONException;

class View
{
    public static function render(string $template, array $data = [], string $dir = BASE_PATH . '/app/View', bool $controller = true): string
    {
        if (!self::isSafeDir($dir)) {
            throw new JSONException("非法路径");
        }

        if (!self::isSafeTemplate($template)) {
            throw new JSONException("非法模版");
        }

        $engine = new \Smarty();
        $engine->setTemplateDir($dir);
        $engine->setCacheDir(BASE_PATH . '/runtime/view/cache');
        $engine->setCompileDir(BASE_PATH . '/runtime/view/compile');
        $engine->left_delimiter = '#{';
        $engine->right_delimiter = '}';
        $engine->registerFilter('pre', static function (string $source): string {
            $source = (string)preg_replace(
                '/\|strip_tags(?!\s*:)/',
                "|unescape:'html'|strip_tags",
                $source
            );
            return (string)preg_replace(
                '/\|escape:([\'"])html\1(?!\s*:)/',
                "|escape:'html':null:false",
                $source
            );
        });
        foreach ($data as $key => $item) {
            $engine->assign($key, $item);
        }
        $result = $engine->fetch($template);
        if (\App\Util\Csp::enabled()) {
            $result = \App\Util\Csp::injectNonce($result);
        }
        $controller && hook(\App\Consts\Hook::RENDER_VIEW, $result);
        return $result;
    }

    public static function isSafeDir(string $dir): bool
    {
        $dirReal = realpath($dir);
        if ($dirReal === false) {
            return false;
        }

        $allowPaths = [
            realpath(BASE_PATH . '/app/View'),
            realpath(BASE_PATH . '/app/Pay'),
            realpath(BASE_PATH . '/app/Plugin')
        ];

        foreach ($allowPaths as $base) {
            if ($base !== false && str_starts_with($dirReal, $base)) {
                return true;
            }
        }

        return false;
    }

    public static function isSafeTemplate(string $file): bool
    {
        $allowedExt = ['html', 'hook', 'tpl'];

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt, true)) {
            return false;
        }

        if (!preg_match('/^[a-zA-Z0-9_\-\/\.]+$/', $file)) {
            return false;
        }

        if (str_contains($file, '../') || str_contains($file, '..\\') || $file === '..') {
            return false;
        }

        return true;
    }
}