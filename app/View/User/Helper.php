<?php
declare(strict_types=1);


use App\Consts\Hook;

if (!function_exists("index_var")) {
    function index_var(): string
    {
        return set_script_var([
            "DEBUG" => DEBUG,
            "LANG" => \Kernel\Util\Lang::get(),
            "CURRENCY" => \App\Util\Currency::vars(),
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


if (!function_exists("user_header_nav")) {
    /**
     * 商城前台顶部导航数据源：内置项(购物/订单查询) + USER_VIEW_HEADER_NAV(0x88) 插件项。
     *
     * 条目契约（老版 PHP 主题直取 name/url/icon/target 四键，插件返回时必须齐全）：
     *   name   已翻译的显示名
     *   url    链接
     *   icon   FontAwesome 类（可空串，纯文字主题忽略）
     *   micon  Material Symbols 图标名（可空串，材质图标主题用，如 NewYork）
     *   target _self|_blank
     *   match  active 高亮的路由前缀（可空串 = 不高亮；模板侧必须先判空再调 active()，
     *          否则 active('') 对任何路由都命中）
     *   key    shop|query|''(插件项)，供个别主题按键裁剪（如 NewYork 不渲染 shop）
     *
     * $overrides 用于主题微调内置项的文案/图标而不复制整个数组：
     *   user_header_nav(['shop' => ['name' => t('商城')], 'query' => ['icon' => '...']])
     *
     * 老版 PHP 主题（Toka/Magic 等）仍直接 foreach hook(0x88)，本函数只服务数组化后的模板。
     */
    function user_header_nav(array $overrides = []): array
    {
        $builtins = [
            "shop" => [
                "name" => lang("购物", "tpl"),
                "url" => "/",
                "icon" => "fa-duotone fa-regular fa-cart-shopping",
                "micon" => "storefront",
                "target" => "_self",
                "match" => "/user/index/index",
            ],
            "query" => [
                "name" => lang("订单查询", "tpl"),
                "url" => "/user/index/query",
                "icon" => "fa-duotone fa-regular fa-folders",
                "micon" => "receipt_long",
                "target" => "_self",
                "match" => "/user/index/query",
            ],
        ];

        $items = [];
        foreach ($builtins as $key => $item) {
            if (isset($overrides[$key]) && is_array($overrides[$key])) {
                $item = array_merge($item, $overrides[$key]);
            }
            $item["key"] = $key;
            $items[] = $item;
        }

        //hook 收集模式可能混入 string/object（其它插件返回值），只收合法条目
        $hooked = hook(Hook::USER_VIEW_HEADER_NAV);
        if (is_array($hooked)) {
            foreach ($hooked as $item) {
                if (!is_array($item) || empty($item['name']) || empty($item['url'])) {
                    continue;
                }
                $items[] = [
                    "name" => (string)$item['name'],
                    "url" => (string)$item['url'],
                    "icon" => (string)($item['icon'] ?? ''),
                    "micon" => (string)($item['micon'] ?? ''),
                    "svg" => (string)($item['svg'] ?? ''),
                    "target" => ((string)($item['target'] ?? '')) ?: '_self',
                    "match" => (string)($item['match'] ?? ''),
                    "key" => "",
                ];
            }
        }

        return $items;
    }
}

if (!function_exists("user_nav_icon")) {
    /**
     * 顶栏导航条目的图标 HTML。
     *
     * 为什么要有这个函数：各套主题装的图标字体版本不一样（Toka/Dream 等是 FontAwesome 4 的
     * `fa fa-xxx`，Cartoon/Chiba 等是 FA6 的 `fa-duotone fa-regular fa-xxx`），插件返回的
     * class 只能命中其中一边，另一边就渲染成宽度 0 的空白。内联 SVG 不依赖字体，
     * 用 currentColor + 1em 尺寸就能跟着各主题的文字颜色和字号走。
     *
     * 优先级：svg > icon（class）。两个都没有就返回空串，主题按纯文字渲染。
     *
     * @param array $item 导航条目（user_header_nav() 的元素，或 0x88 hook 的返回值）
     * @param string $class 主题自己的图标类，会并进 <svg>/<i> 里
     */
    function user_nav_icon(array $item, string $class = ''): string
    {
        $svg = trim((string)($item['svg'] ?? ''));
        //条目来自插件代码而不是用户输入，但插件是第三方的，这里仍挡一道：
        //必须是真正的 <svg> 开头，且不许夹带脚本或事件处理器
        if ($svg !== ''
            && stripos($svg, '<svg') === 0
            && !preg_match('/<script|\son\w+\s*=|javascript:/i', $svg)) {
            if ($class !== '') {
                $safe = htmlspecialchars($class, ENT_QUOTES);
                if (preg_match('/<svg\b[^>]*\sclass\s*=\s*"/i', $svg)) {
                    //已有 class 就并进去，别再塞第二个 class 属性
                    $svg = preg_replace('/(<svg\b[^>]*\sclass\s*=\s*")/i', '$1' . $safe . ' ', $svg, 1);
                } else {
                    $svg = preg_replace('/<svg\b/i', '<svg class="' . $safe . '"', $svg, 1);
                }
            }
            return $svg;
        }

        $icon = trim((string)($item['icon'] ?? ''));
        if ($icon === '') {
            return '';
        }
        $cls = trim($icon . ' ' . $class);
        return '<i class="' . htmlspecialchars($cls, ENT_QUOTES) . '" aria-hidden="true"></i>';
    }
}

