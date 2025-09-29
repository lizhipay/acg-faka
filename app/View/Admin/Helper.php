<?php
declare(strict_types=1);


use App\Consts\Hook;

if (!function_exists("admin_var")) {
    function admin_var(): string
    {
        return set_script_var([
            "DEBUG" => DEBUG,
            "HACK_ROUTE_TABLE_COLUMNS" => hook(Hook::HACK_ROUTE_TABLE_COLUMNS),
            "HACK_SUBMIT_FORM" => hook(Hook::HACK_SUBMIT_FORM),
            "HACK_SUBMIT_TAB" => hook(Hook::HACK_SUBMIT_TAB)
        ]);
    }
}


