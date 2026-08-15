<?php
declare(strict_types=1);


use App\Consts\Hook;

if (!function_exists("index_var")) {
    function index_var(): string
    {
        return set_script_var([
            "DEBUG" => DEBUG,
            "LANG" => \Kernel\Util\Lang::get(),
            "CAT_ID" => (int)$_GET['cid'],
            "HACK_ROUTE_TABLE_COLUMNS" => hook(Hook::HACK_ROUTE_TABLE_COLUMNS),
            "HACK_SUBMIT_FORM" => hook(Hook::HACK_SUBMIT_FORM),
            "HACK_SUBMIT_TAB" => hook(Hook::HACK_SUBMIT_TAB),
            "HACK_ROUTE_TABLE_SEARCH" => hook(Hook::HACK_ROUTE_TABLE_SEARCH)
        ]) . lang_dict_script();
    }
}


if (!function_exists("contact_type_msg")) {
    function contact_type_msg(int $type): string
    {
        //联系方式：0=任意，1=手机，2=邮箱，3=QQ
        return lang(match ($type) {
            0 => "联系方式",
            1 => "手机号",
            2 => "邮箱地址",
            3 => "QQ号"
        }, "tpl");
    }
}


if (!function_exists("widget_render")) {
    function widget_render(mixed $widgets): string
    {
        if (!is_array($widgets) || count($widgets) == 0) {
            return "";
        }

        $html = "";

        foreach ($widgets as $widget) {
            //custom：由 JS 接管渲染的自定义组件容器（如插件注入的人机验证），无标签、无输入项、不参与下单校验
            if (($widget['type'] ?? '') == "custom") {
                $customName = htmlspecialchars((string)($widget['name'] ?? ''), ENT_QUOTES);
                if ($customName !== '') {
                    $html .= <<<HTML
<div class="acg-widget-custom acg-widget-custom-{$customName}" data-widget-custom="{$customName}"></div>
HTML;
                }
                continue;
            }

            $dict = [];
            if (!empty($widget['dict'])) {
                foreach (explode(',', trim($widget['dict'])) as $pair) {
                    [$k, $v] = array_map('trim', explode('=', $pair, 2));
                    if ($k !== '' && $v !== '') {
                        $dict[$v] = $k;
                    }
                }
            }

            $html .= <<<HTML
<div><label class="form-label mb-1">{$widget['cn']}</label>
HTML;


            if (in_array($widget['type'], ["text", "password", "number"])) {
                $html .= <<<HTML
                                    <input type="{$widget['type']}" class="form-control" name="{$widget['name']}"
                                           placeholder="{$widget['placeholder']}">
HTML;

            } elseif ($widget['type'] == "select") {
                $option = <<<HTML
<option value="">{$widget['placeholder']}</option>
HTML;

                foreach ($dict as $key => $value) {
                    $option .= <<<HTML
<option value="{$key}">{$value}</option>
HTML;
                }
                $html .= <<<HTML
<select class="form-control" name="{$widget['name']}">{$option}</select>
HTML;
            } elseif ($widget['type'] == "checkbox") {
                $html .= "<div>";
                foreach ($dict as $key => $value) {
                    $html .= <<<HTML
<div class="form-check form-check-inline">
  <input class="form-check-input" name="{$widget['name']}[]" type="checkbox" id="checkbox-{$key}" value="{$key}">
  <label class="form-check-label" for="checkbox-{$key}">{$value}</label>
</div>
HTML;
                }
                $html .= "</div>";
            } elseif ($widget['type'] == "radio") {
                $html .= "<div>";
                $i = 0;
                foreach ($dict as $key => $value) {
                    $checked = $i == 0 ? "checked" : "";
                    $html .= <<<HTML
<div class="form-check form-check-inline">
  <input class="form-check-input" {$checked} type="radio" name="{$widget['name']}" id="radio-{$key}" value="{$key}">
  <label class="form-check-label" for="radio-{$key}">{$value}</label>
</div>
HTML;
                    $i++;
                }
                $html .= "</div>";
            } elseif ($widget['type'] == "textarea") {
                $html .= <<<HTML
<textarea class="form-control" name="{$widget['name']}" rows="3"></textarea>
HTML;

            }
            $html .= "</div>";
        }


        return $html;
    }
}


if (!function_exists("item_var")) {
    function item_var(array $item): string
    {
        unset($item['description']);
        return set_script_var([
            "_var_item" => $item
        ]);
    }
}

