<?php
declare(strict_types=1);


use App\Consts\Hook;

if (!function_exists("admin_var")) {
    function admin_var(): string
    {
        return set_script_var([
            "DEBUG" => DEBUG,
            "LANG" => \Kernel\Util\Lang::get(),
            //站点语言清单：切换器的当前项标记、按钮角标都从这里取，
            //站长加了语言或停用了某种语言，前端跟着变，不用改任何模板
            "LANGS" => \Kernel\Util\Lang::menu(),
            "CURRENCY" => \App\Util\Currency::vars(),
            "HACK_ROUTE_TABLE_COLUMNS" => hook(Hook::HACK_ROUTE_TABLE_COLUMNS),
            "HACK_SUBMIT_FORM" => hook(Hook::HACK_SUBMIT_FORM),
            "HACK_SUBMIT_TAB" => hook(Hook::HACK_SUBMIT_TAB),
            "HACK_ROUTE_TABLE_SEARCH" => hook(Hook::HACK_ROUTE_TABLE_SEARCH)
        ]) . lang_dict_script();
    }
}


