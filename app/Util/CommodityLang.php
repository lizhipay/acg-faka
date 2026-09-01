<?php
declare(strict_types=1);

namespace App\Util;

use Kernel\Util\Lang;

final class CommodityLang
{
    private const TAG_KEYS = ["text"];

    private const WIDGET_KEYS = ["cn", "placeholder", "error"];

    private const TEXT_FIELDS = ["delivery_message", "leave_message", "card_show_tips"];

    public static function detail(array $item): array
    {
        if (isset($item['name']) && is_string($item['name'])) {
            $item['name'] = Lang::trans($item['name'], "dyn");
        }
        if (isset($item['description']) && is_string($item['description'])) {
            $item['description'] = RichHtml::present(Lang::trans($item['description'], "dyn"));
        }
        if (isset($item['tags'])) {
            $item['tags'] = Lang::transObjectList($item['tags'], self::TAG_KEYS);
        }
        if (isset($item['widget'])) {
            $item['widget'] = self::widget($item['widget']);
        }
        foreach (self::TEXT_FIELDS as $field) {
            if (!empty($item[$field]) && is_string($item[$field])) {
                $item[$field] = Lang::trans($item[$field], "dyn");
            }
        }
        return $item;
    }

    public static function listTags(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (is_array($row) && isset($row['tags'])) {
                $rows[$i]['tags'] = Lang::transObjectList($row['tags'], self::TAG_KEYS);
            }
        }
        return $rows;
    }

    private static function widget(mixed $widget): mixed
    {
        $widget = Lang::transObjectList($widget, self::WIDGET_KEYS);

        $wasJson = is_string($widget);
        $list = $wasJson ? json_decode($widget, true) : $widget;
        if (!is_array($list) || $list === []) {
            return $widget;
        }

        $changed = false;
        foreach ($list as $i => $field) {
            if (!is_array($field) || empty($field['dict']) || !is_string($field['dict'])) {
                continue;
            }
            $pairs = [];
            foreach (explode(',', $field['dict']) as $pair) {
                $kv = explode('=', $pair, 2);
                if (count($kv) !== 2) {
                    $pairs[] = $pair;
                    continue;
                }
                $label = trim($kv[0]);
                $translated = $label === "" ? $label : Lang::trans($label, "dyn");
                if ($translated !== $label) {
                    $changed = true;
                }
                $pairs[] = $translated . '=' . $kv[1];
            }
            $list[$i]['dict'] = implode(',', $pairs);
        }

        if (!$changed) {
            return $widget;
        }
        return $wasJson ? (string)json_encode($list, JSON_UNESCAPED_UNICODE) : $list;
    }
}
