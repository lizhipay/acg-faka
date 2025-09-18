<?php
declare(strict_types=1);


if (!function_exists("index_var")) {
    function index_var(): string
    {
        return set_script_var([
            "DEBUG" => DEBUG,
            "CAT_ID" => (int)$_GET['cid']
        ]);
    }
}


if (!function_exists("contact_type_msg")) {
    function contact_type_msg(int $type): string
    {
        //联系方式：0=任意，1=手机，2=邮箱，3=QQ
        return match ($type) {
            0 => "联系方式",
            1 => "手机号",
            2 => "邮箱地址",
            3 => "QQ号"
        };
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

