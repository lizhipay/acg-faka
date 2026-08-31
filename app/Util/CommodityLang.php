<?php
declare(strict_types=1);

namespace App\Util;

use Kernel\Util\Lang;

/**
 * 商品展示文案的翻译出口。
 *
 * 这些字段全是站长在后台自填的中文，买家可见，但既不在静态词包里、主题也不会各自记得包 lang()
 * （19 个主题里只有 Cartoon 包了一部分，issue #832）。所以统一在控制器出口翻译，
 * 主题与前端 JS 拿到的就已经是当前语言。
 *
 * 只翻展示文案：name(字段键)、color、regex、type、code 这类标识与样式值一概不动，
 * 下单仍按 id / 字段键提交，翻译不影响任何提交契约。
 */
final class CommodityLang
{
    /** tags: [{"text":"限时特惠","color":"orange"}] —— 只翻 text */
    private const TAG_KEYS = ["text"];

    /** widget: [{"cn":"测试账号","name":"username","placeholder":"请输入…","error":"…"}] —— name 是提交用的字段键，不能翻 */
    private const WIDGET_KEYS = ["cn", "placeholder", "error"];

    /** 纯文本提示列 */
    private const TEXT_FIELDS = ["delivery_message", "leave_message", "card_show_tips"];

    /**
     * 商品详情：服务端渲染(User\Index::item)与接口(User\Api\Index::commodityDetail)共用
     * @param array $item
     * @return array
     */
    public static function detail(array $item): array
    {
        if (isset($item['name']) && is_string($item['name'])) {
            $item['name'] = Lang::trans($item['name'], "dyn");
        }
        //详情是富文本，dyn 场景由翻译插件的富文本模型整段处理
        if (isset($item['description']) && is_string($item['description'])) {
            $item['description'] = Lang::trans($item['description'], "dyn");
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

    /**
     * 商品列表：卡片上只露出 name 与 tags，name 由调用方的 transList 统一处理，这里补 tags
     * @param array $rows
     * @return array
     */
    public static function listTags(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (is_array($row) && isset($row['tags'])) {
                $rows[$i]['tags'] = Lang::transObjectList($row['tags'], self::TAG_KEYS);
            }
        }
        return $rows;
    }

    /**
     * 自定义下单字段：除 cn/placeholder/error 外，dict 是下拉选项（"选项名=提交值" 逗号分隔），
     * 只翻选项名，提交值必须原样保留，否则下单数据对不上。
     * @param mixed $widget
     * @return mixed
     */
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
