<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\ManageLog;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Util\Lang as LangUtil;
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
        if ($lang !== '' && in_array($lang, LangUtil::LANGS, true)) {
            $query->where('lang', $lang);
        }

        $status = $_POST['equal-status'] ?? '';
        if ($status !== '' && $status !== null) {
            $query->where('status', (int)$status);
        }

        $scene = trim((string)($_POST['equal-scene'] ?? ''));
        if ($scene !== '') {
            $query->where('scene', $scene);
        }

        $keyword = trim(html_entity_decode((string)($_POST['search-source'] ?? ''), ENT_QUOTES, 'UTF-8'));
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
            hook(\App\Consts\Hook::LANG_MISS, array_keys($sources), array_keys($langs));
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
        foreach (LangUtil::LANGS as $lang) {
            if ($lang === LangUtil::SOURCE) {
                continue;
            }
            $total = (int)DB::table(LangUtil::TABLE)->where('lang', $lang)->count();
            $done = (int)DB::table(LangUtil::TABLE)->where('lang', $lang)->whereNotNull('text')->where('text', '!=', '')->count();
            $stat[] = [
                'lang' => $lang,
                'total' => $total,
                'translated' => $done,
                'pending' => $total - $done,
                'percent' => $total > 0 ? (int)floor($done / $total * 100) : 0,
            ];
        }
        return $this->json(200, 'success', ['list' => $stat, 'version' => LangUtil::version()]);
    }
}
