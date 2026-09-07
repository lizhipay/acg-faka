<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\ManageLog;
use App\Util\LangRecycle;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Util\Lang as LangUtil;
use Kernel\Util\LangCatalog;
use Kernel\Waf\Filter;

#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class Lang extends Manage
{
    /**
     * 翻译词条列表
     * @return array
     */
    public function data(): array
    {
        $page = max(1, (int)($_POST['page'] ?? 1));
        $limit = min(200, max(1, (int)($_POST['limit'] ?? 20)));

        $query = DB::table(LangUtil::TABLE);

        $lang = trim((string)($_POST['equal-lang'] ?? ''));
        if ($lang !== '' && LangUtil::known($lang)) {
            //明确挑了语言就照办，含停用语言——停用只是不对外提供，
            //后台还得能翻出它的词条来查看和维护
            $query->where('lang', $lang);
        } else {
            //没挑语言时只列启用中的：站长把日语停了，就不该再被几千条日语词条刷屏（GitHub #887）
            $query->whereIn('lang', LangUtil::targets());
        }

        $status = $_POST['equal-status'] ?? '';
        if ($status !== '' && $status !== null) {
            $query->where('status', (int)$status);
        }

        $scene = trim((string)($_POST['equal-scene'] ?? ''));
        if ($scene !== '') {
            $query->where('scene', $scene);
        }

        $keyword = self::searchKeyword();
        if ($keyword !== '') {
            $query->where(static function ($q) use ($keyword) {
                $q->where('source', 'like', "%{$keyword}%")->orWhere('text', 'like', "%{$keyword}%");
            });
        }

        $total = (int)(clone $query)->count();
        $list = $query->orderByDesc('id')
            ->forPage($page, $limit)
            ->get(['id', 'hash', 'source', 'lang', 'text', 'scene', 'status', 'update_time'])
            ->map(static fn($row) => (array)$row)
            ->toArray();

        return $this->json(200, 'success', ['list' => $list, 'total' => $total]);
    }

    /**
     * 取搜索关键词。
     *
     * 前端把关键词按 UTF-8 转成十六进制放在 search-source-hex 里传，原因是 WAF 的 POST 规则
     * 里有一条 `\<(iframe|script|body|img|layer|div|meta|style|…)`，而商品详情词条恰恰就是
     * 这些标签构成的——直接搜 `<div class` 会让**整个请求**被防火墙拦掉，返回的还是
     * 「当前会话不安全，请刷新网页」，根本看不出是关键词被拦了（GitHub #888）。
     *
     * 十六进制只有 0-9a-f，拼不出规则里任何一个关键词，所以一定能穿过去；关键词本身只进
     * 参数化的 LIKE，不会有注入面。老前端仍可用明文 search-source。
     *
     * @return string
     */
    private static function searchKeyword(): string
    {
        $hex = (string)($_POST['search-source-hex'] ?? '');
        if ($hex !== '' && preg_match('/^[0-9a-fA-F]{2,4096}$/', $hex) && strlen($hex) % 2 === 0) {
            $decoded = @hex2bin($hex);
            if (is_string($decoded) && $decoded !== '' && mb_check_encoding($decoded, 'UTF-8')) {
                //控制字符会把 LIKE 搞成永远匹配不到，顺手清掉
                return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $decoded) ?? '');
            }
        }
        return trim(html_entity_decode((string)($_POST['search-source'] ?? ''), ENT_QUOTES, 'UTF-8'));
    }

    /**
     * 保存译文（人工确认）
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function save(Request $request): array
    {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new JSONException('参数不正确');
        }
        $row = DB::table(LangUtil::TABLE)->where('id', $id)->first();
        if (!$row) {
            throw new JSONException('词条不存在');
        }

        //译文是人工录入的富文本，取未过滤原文
        $text = $request->unsafePost('text');
        if (!is_string($text)) {
            $text = (string)($_POST['text'] ?? '');
        }
        $text = trim($text);
        if (str_contains($text, "\0") || mb_strlen($text) > 20000) {
            throw new JSONException('译文内容不合法');
        }

        DB::table(LangUtil::TABLE)->where('id', $id)->update([
            'text' => $text === '' ? null : $text,
            'status' => $text === '' ? 0 : 2,
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        LangUtil::rebuild((string)$row->lang);

        ManageLog::log($this->getManage(), "[翻译管理]修改词条#{$id}");
        return $this->json(200, '（＾∀＾）保存成功');
    }

    /**
     * 删除词条
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {
        $list = array_values(array_filter(array_map('intval', (array)($_POST['list'] ?? []))));
        if ($list === []) {
            throw new JSONException('你还没有选择词条');
        }
        $langs = DB::table(LangUtil::TABLE)->whereIn('id', $list)->pluck('lang')->unique()->all();
        $count = DB::table(LangUtil::TABLE)->whereIn('id', $list)->delete();
        foreach ($langs as $lang) {
            LangUtil::rebuild((string)$lang);
        }
        ManageLog::log($this->getManage(), "[翻译管理]删除词条 {$count} 条");
        return $this->json(200, "已删除 {$count} 条", ['count' => $count]);
    }

    /**
     * 标记重译：清空译文并重新触发 LANG_MISS，交由翻译插件处理
     * @return array
     * @throws JSONException
     */
    public function retranslate(): array
    {
        $list = array_values(array_filter(array_map('intval', (array)($_POST['list'] ?? []))));
        if ($list === []) {
            throw new JSONException('你还没有选择词条');
        }
        $rows = DB::table(LangUtil::TABLE)->whereIn('id', $list)->get(['source', 'lang']);
        DB::table(LangUtil::TABLE)->whereIn('id', $list)->update([
            'text' => null,
            'status' => 0,
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        $sources = [];
        $langs = [];
        foreach ($rows as $row) {
            $sources[(string)$row->source] = true;
            $langs[(string)$row->lang] = true;
        }
        foreach (array_keys($langs) as $lang) {
            LangUtil::rebuild($lang);
        }
        if ($sources !== []) {
            //hook() 的变参是按引用接收的，函数返回值直接传会报 "Only variables should be passed by reference"
            $sourceList = array_keys($sources);
            $langList = array_keys($langs);
            hook(\App\Consts\Hook::LANG_MISS, $sourceList, $langList);
        }

        $count = count($rows);
        ManageLog::log($this->getManage(), "[翻译管理]标记重译 {$count} 条");
        return $this->json(200, "已标记 {$count} 条待重新翻译", ['count' => $count]);
    }

    /**
     * 把库里所有待翻译条目重新投递给翻译插件。
     *
     * 词条入库与队列投递是两件事：队列文件若因崩溃、清盘、插件重装丢失，条目就会
     * 一直停在 status=0 且无人再投递——只有对应页面被再次访问才会重新触发。
     * 这里直接按数据库重发一次 LANG_MISS，不动任何已有译文。
     *
     * @return array
     */
    public function resend(): array
    {
        $rows = DB::table(LangUtil::TABLE)
            ->where(function ($query) {
                $query->whereNull('text')->orWhere('text', '');
            })
            ->get(['source', 'lang']);

        $sources = [];
        $langs = [];
        foreach ($rows as $row) {
            $sources[(string)$row->source] = true;
            $langs[(string)$row->lang] = true;
        }

        if ($sources === []) {
            return $this->json(200, '没有待翻译的词条', ['count' => 0]);
        }

        //一次投太多会把队列撑爆，分批交给钩子
        $sourceList = array_keys($sources);
        $langList = array_keys($langs);
        foreach (array_chunk($sourceList, 200) as $chunk) {
            hook(\App\Consts\Hook::LANG_MISS, $chunk, $langList);
        }

        $count = count($sourceList);
        ManageLog::log($this->getManage(), "[翻译管理]补投待翻条目 {$count} 条");
        return $this->json(200, "已补投 {$count} 条待翻译词条", ['count' => $count]);
    }

    /**
     * 扫描插件/支付插件/模板自带的词包并导入。
     *
     * 正常安装扩展时会自动导入一次；手工把扩展丢进目录、或直接改了扩展里的
     * Lang/*.json 时，用这个按钮补一次。
     *
     * @return array
     */
    public function scanPacks(): array
    {
        $result = LangUtil::scanExtensionPacks((bool)($_POST['force'] ?? false));

        $packs = (int)$result['packs'];
        $imported = (int)$result['imported'];
        if ($packs === 0) {
            return $this->json(200, '没有发现扩展词包', $result);
        }
        if ($imported === 0) {
            return $this->json(200, "已扫描 {$packs} 个词包，没有新增词条", $result);
        }

        $names = implode('、', array_slice((array)$result['extensions'], 0, 5));
        ManageLog::log($this->getManage(), "[翻译管理]导入扩展词包 {$imported} 条（{$names}）");
        return $this->json(200, "已从 {$packs} 个词包导入 {$imported} 条词条", $result);
    }

    /**
     * 重建全部语言缓存
     * @return array
     */
    public function rebuild(): array
    {
        LangUtil::rebuild();
        return $this->json(200, '缓存已重建', ['version' => LangUtil::version()]);
    }

    /**
     * 统计概览
     * @return array
     */
    public function stat(): array
    {
        $stat = [];
        foreach (LangUtil::registry() as $lang => $item) {
            if ($lang === LangUtil::SOURCE) {
                continue;
            }
            $total = (int)DB::table(LangUtil::TABLE)->where('lang', $lang)->count();
            $done = (int)DB::table(LangUtil::TABLE)->where('lang', $lang)->whereNotNull('text')->where('text', '!=', '')->count();
            $stat[] = [
                'lang' => $lang,
                'name' => $item['name'],
                'enabled' => $item['enabled'],
                'total' => $total,
                'translated' => $done,
                'pending' => $total - $done,
                'percent' => $total > 0 ? (int)floor($done / $total * 100) : 0,
            ];
        }
        return $this->json(200, 'success', ['list' => $stat, 'version' => LangUtil::version()]);
    }

    /**
     * 清理废弃的动态词条。
     *
     * 翻译库按 md5(原文) 寻址，改一次商品名就是一条新原文、旧的没人认领，删商品也不会带走
     * 它的词条。日常保存/删除已经会顺手回收，这个按钮是给历史遗留的存量用的（GitHub #888）。
     *
     * @return array
     */
    public function recycle(): array
    {
        $r = LangRecycle::sweep();

        if ($r['deleted'] === 0) {
            return $this->json(200, "扫描了 {$r['scanned']} 条动态词条，没有发现废弃的", $r);
        }

        ManageLog::log($this->getManage(), "[翻译管理]清理废弃词条 {$r['deleted']} 条");
        return $this->json(200, "已清理 {$r['dead']} 条废弃原文、共 {$r['deleted']} 行译文", $r);
    }

    // ---------------- 语言管理：内置语言启停 + 自定义语言 ----------------

    /**
     * 语言列表（含停用），带各语言的词条统计
     * @return array
     */
    public function langs(): array
    {
        $counts = DB::table(LangUtil::TABLE)
            ->selectRaw('lang, count(*) as total, sum(case when text is null or text = \'\' then 0 else 1 end) as translated')
            ->groupBy('lang')
            ->get()
            ->keyBy('lang');

        $registry = LangUtil::registry();
        $list = [];
        foreach ($registry as $code => $item) {
            $row = $counts[$code] ?? null;
            $total = (int)($row->total ?? 0);
            $done = (int)($row->translated ?? 0);
            $list[] = $item + [
                    'source' => $code === LangUtil::SOURCE,
                    'total' => $total,
                    'translated' => $done,
                    'pending' => $total - $done,
                    'percent' => $total > 0 ? (int)floor($done / $total * 100) : 0,
                ];
        }

        //还没加进站点的语言，供「添加语言」的下拉框搜索选择
        $options = [];
        foreach (LangCatalog::all() as $item) {
            if (isset($registry[$item['code']])) {
                continue;
            }
            //中文名、母语名、语言代码都放进选项文本里，输入其中任意一种都能搜到
            $options[] = ['id' => $item['code'], 'name' => "{$item['zh']} · {$item['name']}（{$item['code']}）"];
        }

        return $this->json(200, 'success', ['list' => $list, 'options' => $options]);
    }

    /**
     * 新增一种语言 / 启停已有语言。
     *
     * 语言代码只能从内置语言表里选：它是词条表 lang 列的值、runtime/lang 的文件名、
     * Cookie 与前端 URL 的取值，手输一个自造代码只会得到一门谁也翻不了的语言。
     * 显示名与交给 AI 的目标语言描述同样由语言表给定，站长改不了——
     * 名字写错前台就直接错给访客看，提示词写含糊译文就会跑偏。
     *
     * @return array
     * @throws JSONException
     */
    public function langSave(): array
    {
        $code = LangUtil::normalizeCode((string)($_POST['code'] ?? ''));
        if ($code === '') {
            throw new JSONException('请选择语言');
        }

        $registry = LangUtil::registry();
        $exists = isset($registry[$code]);

        if (!$exists && !LangCatalog::has($code)) {
            throw new JSONException('语言表里没有这门语言，请从下拉列表中选择');
        }

        $enabled = (int)($_POST['enabled'] ?? 1) === 1;
        if ($code === LangUtil::SOURCE && !$enabled) {
            throw new JSONException('源语言不能停用');
        }

        $rows = [];
        foreach ($registry as $item) {
            if ($item['code'] === $code) {
                $item['enabled'] = $enabled;
            }
            $rows[] = $item;
        }
        if (!$exists) {
            $rows[] = ['code' => $code, 'enabled' => $enabled];
        }
        $saved = LangUtil::saveRegistry($rows);
        $name = $saved[$code]['name'] ?? $code;

        if ($exists) {
            ManageLog::log($this->getManage(), '[翻译管理]' . ($enabled ? '启用' : '停用') . "语言 {$code}");
            return $this->json(200, $enabled ? "已启用 {$name}" : "已停用 {$name}，访客不再能切到它，翻译插件也不再为它翻译");
        }

        ManageLog::log($this->getManage(), "[翻译管理]新增语言 {$code}");
        return $this->json(200, "已添加 {$name}，接下来点「补齐词条」把现有词条复制给它并交给翻译插件");
    }

    /**
     * 删除自定义语言。内置语言只能停用不能删。
     * @return array
     * @throws JSONException
     */
    public function langDel(): array
    {
        $code = LangUtil::normalizeCode((string)($_POST['code'] ?? ''));
        $registry = LangUtil::registry();
        if ($code === '' || !isset($registry[$code])) {
            throw new JSONException('语言不存在');
        }
        if ($registry[$code]['builtin']) {
            throw new JSONException('内置语言只能停用，不能删除');
        }

        $rows = [];
        foreach ($registry as $item) {
            if ($item['code'] !== $code) {
                $rows[] = $item;
            }
        }
        LangUtil::saveRegistry($rows);

        //连词条一起删：留着既占库又永远不会被读到（前台已经解析不出这个语言了）
        $count = (int)DB::table(LangUtil::TABLE)->where('lang', $code)->delete();
        @unlink(BASE_PATH . "/runtime/lang/{$code}.php");

        ManageLog::log($this->getManage(), "[翻译管理]删除语言 {$code}（词条 {$count} 条）");
        return $this->json(200, "已删除 {$registry[$code]['name']}，同时清理词条 {$count} 条", ['count' => $count]);
    }

    /**
     * 给一种语言补齐词条：把库里已有的原文全量复制成该语言的待翻条目，再投递给翻译插件。
     *
     * 新加的语言在库里一条记录都没有，只靠访客访问页面时的 miss 收集要攒很久才够用；
     * 这里直接按现有语言的原文清单铺一份，AI 插件就能一次性把整站翻完。
     *
     * @return array
     * @throws JSONException
     */
    public function langSeed(): array
    {
        $code = LangUtil::normalizeCode((string)($_POST['code'] ?? ''));
        if ($code === '' || !LangUtil::known($code) || $code === LangUtil::SOURCE) {
            throw new JSONException('语言不存在');
        }

        //以词条最全的那个语言为模板；一条都没有（全新站点）就没东西可补
        $reference = DB::table(LangUtil::TABLE)
            ->selectRaw('lang, count(*) as total')
            ->where('lang', '!=', $code)
            ->groupBy('lang')
            ->orderByDesc('total')
            ->first();
        if (!$reference) {
            return $this->json(200, '库里还没有任何词条，正常访问几个页面后会自动收集', ['count' => 0]);
        }

        $now = date('Y-m-d H:i:s');
        $inserted = 0;
        $langList = [$code];

        //按模板语言分批搬运。这里插入的是 lang={$code} 的新行，不在被遍历的结果集内，
        //所以 chunk 的偏移量不会因为自己的插入而错位。
        DB::table(LangUtil::TABLE)
            ->where('lang', (string)$reference->lang)
            ->orderBy('id')
            ->chunk(500, function ($items) use ($code, $now, &$inserted, $langList) {
                $rows = [];
                $sources = [];
                foreach ($items as $item) {
                    $rows[] = [
                        'hash' => (string)$item->hash,
                        'source' => (string)$item->source,
                        'lang' => $code,
                        'text' => null,
                        'scene' => (string)$item->scene,
                        'status' => 0,
                        'create_time' => $now,
                        'update_time' => $now,
                    ];
                    $sources[] = (string)$item->source;
                }
                //已存在的原样跳过，不会覆盖已翻好的译文
                $inserted += (int)DB::table(LangUtil::TABLE)->insertOrIgnore($rows);
                //边搬边投递，避免把整站原文攒在内存里再一次性丢给队列
                hook(\App\Consts\Hook::LANG_MISS, $sources, $langList);
            });

        ManageLog::log($this->getManage(), "[翻译管理]为语言 {$code} 补齐词条 {$inserted} 条");
        return $this->json(200, "已为 " . LangUtil::name($code) . " 补齐 {$inserted} 条待翻词条并投递给翻译插件", ['count' => $inserted]);
    }
}
