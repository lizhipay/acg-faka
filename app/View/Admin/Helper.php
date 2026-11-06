<?php
declare(strict_types=1);


use App\Consts\Hook;

if (!function_exists("admin_table_columns")) {
    /**
     * 插件追加的表格列。退款已并入核心（#1145），老「订单退款」插件的按钮列不再显示，免得订单列表出现两个退款入口
     */
    function admin_table_columns(): mixed
    {
        $columns = hook(Hook::HACK_ROUTE_TABLE_COLUMNS);
        if (!is_array($columns)) {
            return $columns;
        }
        return array_values(array_filter($columns, static function ($column): bool {
            return !is_array($column) || !str_contains((string)($column['code'] ?? ''), "_refund_button_hook");
        }));
    }
}

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
            //后台闲置锁屏分钟数（0=关闭），全局锁屏脚本据此计时
            "LOCK_TIMEOUT" => \App\Util\AdminLock::timeoutMinutes(),
            "HACK_ROUTE_TABLE_COLUMNS" => admin_table_columns(),
            //退款等站长专属操作的按钮只给站长看（接口另有 Owner 拦截）
            "MANAGE_OWNER" => (int)(\App\Util\Context::get(\App\Consts\Manage::SESSION)?->type ?? 1) === 0,
            "HACK_SUBMIT_FORM" => hook(Hook::HACK_SUBMIT_FORM),
            "HACK_SUBMIT_TAB" => hook(Hook::HACK_SUBMIT_TAB),
            "HACK_ROUTE_TABLE_SEARCH" => hook(Hook::HACK_ROUTE_TABLE_SEARCH)
        ]) . lang_dict_script();
    }
}


