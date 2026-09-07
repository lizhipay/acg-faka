<?php
declare(strict_types=1);

namespace App\Util;

use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Util\Lang;

/**
 * 动态内容(scene=dyn)词条的回收。
 *
 * 翻译库是「内容寻址」的：主键是 md5(原文)，词条只认文本、不认对象。这带来一个好处
 * （十个商品都叫「自动发货」只占一条词条、只翻一次）和一个代价：改一次商品名就是一条
 * 新原文，旧的那条没人认领；删掉商品，它的词条也不会跟着走。日子久了词条表里全是
 * 没人用的东西，翻译管理页翻都翻不完（GitHub #888）。
 *
 * 这里补上回收：商品/分类保存或删除时，把「换下来的旧文案」交给 release()，
 * 确认全站再没有别的地方在用它，就把它的 dyn 词条删掉。
 *
 * 几条刻意的边界：
 *  - 只回收 scene=dyn。tpl/js/api/ext 是程序里的固定文案，跟数据无关，动不得。
 *  - 只按「纯文本字段」判断在用与否（商品名、描述、发货/留言文案、分类名、支付方式名、
 *    订单上的发货留言快照）。商品标签与自定义控件的文案埋在 JSON 里，判定要全表反序列化，
 *    代价和误删风险都不划算，而且它们又短又高度复用，留着不亏。
 *  - 判错了也不会坏事：词条没了，下次页面再出现同一段文本会作为 miss 重新入库并重新翻译，
 *    顶多浪费一次翻译调用，不会让访客看到空白。
 */
final class LangRecycle
{
    /**
     * 商品身上会被翻译的纯文本字段（与 CommodityLang 保持一致）
     */
    public const COMMODITY_FIELDS = ['name', 'description', 'delivery_message', 'leave_message', 'card_show_tips'];

    /**
     * 一次最多处理多少条文本，防止批量删商品时把语句撑爆
     */
    private const MAX_BATCH = 500;

    /**
     * 从一个商品模型/数组里取出所有会被翻译的纯文本
     *
     * @param mixed $commodity
     * @return string[]
     */
    public static function commodityTexts(mixed $commodity): array
    {
        if (is_array($commodity)) {
            $row = $commodity;
        } elseif ($commodity instanceof \Illuminate\Database\Eloquent\Model) {
            //取原始属性而不是 toArray()：后者会被 $hidden/$appends 影响，漏字段就等于漏回收
            $row = $commodity->getAttributes();
        } elseif (is_object($commodity)) {
            $row = (array)$commodity;
        } else {
            return [];
        }

        $out = [];
        foreach (self::COMMODITY_FIELDS as $field) {
            $v = $row[$field] ?? null;
            if (is_string($v) && trim($v) !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * 释放一批文案：其中确实已经没人引用的，删掉它们的 dyn 词条。
     *
     * 永不抛错——回收失败绝不能把商品保存/删除带崩。
     *
     * @param string[] $texts 被换下来或随对象一起删掉的文案
     * @return int 删除的词条行数
     */
    public static function release(array $texts): int
    {
        try {
            $texts = self::normalize($texts);
            if ($texts === []) {
                return 0;
            }

            $used = self::inUse($texts);
            $dead = array_values(array_diff($texts, $used));
            if ($dead === []) {
                return 0;
            }

            $hashes = array_map('md5', $dead);
            $langs = DB::table(Lang::TABLE)
                ->where('scene', 'dyn')
                ->whereIn('hash', $hashes)
                ->pluck('lang')
                ->unique()
                ->all();

            $deleted = (int)DB::table(Lang::TABLE)
                ->where('scene', 'dyn')
                ->whereIn('hash', $hashes)
                ->delete();

            foreach ($langs as $lang) {
                Lang::rebuild((string)$lang);
            }

            return $deleted;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 全库扫一遍，把 dyn 里已经没人引用的词条全删掉。
     * 给后台「清理废弃词条」按钮用——历史遗留的那些只能靠它扫。
     *
     * @return array{scanned:int, dead:int, deleted:int}
     */
    public static function sweep(): array
    {
        $result = ['scanned' => 0, 'dead' => 0, 'deleted' => 0];

        $sources = DB::table(Lang::TABLE)
            ->where('scene', 'dyn')
            ->selectRaw('hash, min(source) as source')
            ->groupBy('hash')
            ->get();
        $result['scanned'] = count($sources);
        if ($result['scanned'] === 0) {
            return $result;
        }

        $deadHashes = [];
        //分批比对：一次全塞进 whereIn 会把 SQL 撑爆
        foreach (array_chunk($sources->all(), self::MAX_BATCH) as $chunk) {
            $texts = [];
            $hashOf = [];
            foreach ($chunk as $row) {
                $text = (string)$row->source;
                if (trim($text) === '') {
                    continue;
                }
                $texts[] = $text;
                $hashOf[$text] = (string)$row->hash;
            }
            $used = self::inUse($texts);
            foreach (array_diff($texts, $used) as $text) {
                $deadHashes[] = $hashOf[$text];
            }
        }

        $result['dead'] = count($deadHashes);
        if ($deadHashes === []) {
            return $result;
        }

        $langs = [];
        foreach (array_chunk($deadHashes, self::MAX_BATCH) as $chunk) {
            foreach (DB::table(Lang::TABLE)->where('scene', 'dyn')->whereIn('hash', $chunk)->pluck('lang') as $l) {
                $langs[(string)$l] = true;
            }
            $result['deleted'] += (int)DB::table(Lang::TABLE)->where('scene', 'dyn')->whereIn('hash', $chunk)->delete();
        }
        foreach (array_keys($langs) as $lang) {
            Lang::rebuild($lang);
        }

        return $result;
    }

    /**
     * 这批文案里，哪些还有地方在用
     *
     * @param string[] $texts
     * @return string[]
     */
    private static function inUse(array $texts): array
    {
        $texts = self::normalize($texts);
        if ($texts === []) {
            return [];
        }

        $used = [];
        $probe = static function (string $table, array $columns) use ($texts, &$used): void {
            foreach ($columns as $column) {
                try {
                    foreach (DB::table($table)->whereIn($column, $texts)->pluck($column) as $v) {
                        $used[(string)$v] = true;
                    }
                } catch (\Throwable $e) {
                    //列不存在（老库/改过表）就跳过这一列，别影响其它判定
                }
            }
        };

        $probe('commodity', self::COMMODITY_FIELDS);
        $probe('category', ['name']);
        $probe('pay', ['name']);
        //历史订单上的发货留言是下单那一刻的快照，商品早改了它还在给买家看，不能连它一起删
        $probe('order', ['leave_message']);

        //站点配置里也有几处走 dyn 翻译（商品页标题、公告草稿等），逐条比对即可
        try {
            foreach (DB::table('config')->whereIn('value', $texts)->pluck('value') as $v) {
                $used[(string)$v] = true;
            }
        } catch (\Throwable $e) {
        }

        return array_keys($used);
    }

    /**
     * @param string[] $texts
     * @return string[]
     */
    private static function normalize(array $texts): array
    {
        $out = [];
        foreach ($texts as $text) {
            if (!is_string($text)) {
                continue;
            }
            if (trim($text) === '') {
                continue;
            }
            $out[$text] = true;
        }
        return array_slice(array_keys($out), 0, self::MAX_BATCH);
    }
}
