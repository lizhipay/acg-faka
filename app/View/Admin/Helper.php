<?php
declare(strict_types=1);


if (!function_exists("admin_var")) {
    function admin_var(): string
    {
        return set_script_var([
            "DEBUG" => DEBUG
        ]);
    }
}


