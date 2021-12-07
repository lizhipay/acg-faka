<?php
declare(strict_types=1);

namespace App\Util;

/**
 * 助手
 */
class Helper
{
    /*
     * 获取主题目录所在的URL地址
     */
    public static function themeUrl(string $path, bool $debug = false): string
    {
        $theme = \App\Model\Config::get("user_theme");
        return "/app/View/User/Theme/" . $theme . "/{$path}?v=" . Theme::getConfig($theme)["info"]["VERSION"] . (!$debug ? "" : "&debug=" . Str::generateRandStr(16));
    }
}