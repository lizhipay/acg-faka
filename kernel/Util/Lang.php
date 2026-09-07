<?php
declare (strict_types=1);

namespace Kernel\Util;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * 全站国际化核心服务。
 * 设计约定：
 *  - 以中文原文为 key（与前端 i18n() / 组件层既有调用一致）；
 *  - zh-cn 为源语言，trans() 短路直返，简中请求零额外开销；
 *  - 热路径读 runtime/lang/{lang}.php 数组文件（OPcache 常驻），命中零 DB；
 *  - 未命中(miss)在请求内去重收集，shutdown 时批量落库(status=0 待翻)并触发
 *    Hook::LANG_MISS，由 TranslationBot 等插件异步翻译后经 store/storeBatch 回写；
 *  - 本类不得抛出任何异常影响主流程，所有 IO/DB 均吞错降级为"返回原文"。
 */
final class Lang
{
    public const COOKIE = "acg_lang";
    public const SOURCE = "zh-cn";
    /**
     * 内置语言。
     *
     * 注意：这只是「出厂自带」的四种，不等于站点当前支持的语言——站长可以停用其中任意一种，
     * 也可以自己加语言。要拿站点实际支持的列表请用 langs()/targets()/registry()，
     * 这个常量保留只是为了不打断已经在引用它的老插件。
     */
    public const LANGS = ["zh-cn", "zh-tw", "en", "ja"];
    public const TABLE = "lang";

    /**
     * 语言注册表的配置键（值为 JSON 数组）。
     * 读取一律走 Config::cached()：语言解析在每个请求都跑，绝不能让它去查库加排他锁。
     */
    public const REGISTRY_CONFIG = "lang_registry";

    /**
     * 出厂自带的四种语言：可以停用，但删不掉——已有词包、模板里的写死链接、
     * 老插件对 LANGS 的引用都指望它们始终可解析。
     *
     * 显示名 / AI 目标描述 / 切换器角标一律来自 LangCatalog，不由站长定义。
     */
    private const BUILTIN = ["zh-cn", "zh-tw", "en", "ja"];

    //单条可入库文本的最大长度(字符)，超长的动态文本不进翻译库
    public const MAX_SOURCE_LEN = 500;
    //富文本(商品详情/公告这类带标签的动态内容)单独放宽，与 TranslationBot 的 Queue::MAX_SOURCE_LEN 对齐
    public const MAX_MARKUP_SOURCE_LEN = 8000;
    //每请求最多收集的 miss 条数，防动态变体爆炸
    public const MAX_MISS_PER_REQUEST = 100;

    private static ?string $lang = null;
    private static ?array $registry = null;
    private static ?array $dict = null;
    private static ?array $reverse = null;
    private static array $miss = [];
    //长文本按需点查的请求内缓存：source hash => 译文或 null
    private static array $longCache = [];
    //本请求已返回过的长文本译文，防止「控制器翻过、模板再翻一次」把译文当新原文收集
    private static array $longReverse = [];
    private static bool $shutdownRegistered = false;

    // ---------------- 语言注册表：内置 4 种 + 站长自定义 ----------------

    /**
     * 站点语言注册表。
     *
     * 每项：code / name / zh / prompt / short / enabled / builtin，显示名等元数据一律来自 LangCatalog。
     * 内置语言恒在表内（可停用不可删），站长新增的语言追加在后面，顺序即前台切换器的顺序。
     * 配置损坏、还没安装、数据库连不上时一律降级回内置四种——本方法永不抛错。
     *
     * @return array<string, array{code:string,name:string,zh:string,prompt:string,short:string,enabled:bool,builtin:bool}>
     */
    public static function registry(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }

        $rows = [];
        try {
            //cached() 只读 runtime/config 快照（共享锁，命中不了就返回 null），不碰数据库
            $raw = \App\Model\Config::cached(self::REGISTRY_CONFIG);
            if (is_string($raw) && $raw !== "") {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $rows = $decoded;
                }
            }
        } catch (\Throwable $e) {
        }

        return self::$registry = self::normalizeRegistry($rows);
    }

    /**
     * 把存下来的原始数组补全成完整注册表
     * @param array $rows
     * @return array
     */
    private static function normalizeRegistry(array $rows): array
    {
        $registry = [];

        //先按存储顺序铺开，站长在后台排的序就是前台切换器的序
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = self::normalizeCode((string)($row["code"] ?? ""));
            if ($code === "") {
                continue;
            }
            $registry[$code] = self::makeEntry(
                $code,
                (string)($row["name"] ?? ""),
                (string)($row["prompt"] ?? ""),
                !isset($row["enabled"]) || (bool)$row["enabled"]
            );
        }

        //配置里没提到的内置语言补回默认值：老站点升级、配置被手工删过都能自愈
        foreach (self::BUILTIN as $code) {
            if (!isset($registry[$code])) {
                $registry[$code] = self::makeEntry($code, "", "", true);
            }
        }

        //源语言是整套翻译的基准，既不能停用也不该排在别人后面
        $source = $registry[self::SOURCE];
        $source["enabled"] = true;
        unset($registry[self::SOURCE]);

        return [self::SOURCE => $source] + $registry;
    }

    /**
     * @param string $code
     * @param string $name
     * @param string $prompt
     * @param bool $enabled
     * @return array{code:string,name:string,zh:string,prompt:string,short:string,enabled:bool,builtin:bool}
     */
    private static function makeEntry(string $code, string $name, string $prompt, bool $enabled): array
    {
        $builtin = in_array($code, self::BUILTIN, true);

        //语言表说了算：站长改不了名字，语言表以后修订了也能自动同步到所有站点。
        //存下来的 name/prompt 只在语言表没收录这个代码时兜底（老配置、语言表调整过）。
        $meta = LangCatalog::get($code);
        if ($meta !== null) {
            return $meta + ["enabled" => $enabled, "builtin" => $builtin];
        }

        $name = trim($name);
        $prompt = trim($prompt);
        $name = $name !== "" ? $name : $code;

        return [
            "code" => $code,
            "name" => $name,
            "zh" => $name,
            "prompt" => $prompt !== "" ? $prompt : $name,
            "short" => strtoupper(substr(explode("-", $code)[0], 0, 2)),
            "enabled" => $enabled,
            "builtin" => $builtin,
        ];
    }

    /**
     * 语言码规范化：非法返回空串。
     *
     * 只放行 BCP-47 的小写子集——这个值会直接拼进 runtime/lang/{code}.php 的文件名、
     * Cookie 值与前端 URL，任何路径分隔符/点号都必须挡在门外。
     *
     * @param string $code
     * @return string
     */
    public static function normalizeCode(string $code): string
    {
        $code = strtolower(trim(str_replace("_", "-", $code)));
        if (!preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8}){0,2}$/', $code)) {
            return "";
        }
        return $code;
    }

    /**
     * 站点当前启用的语言码（含源语言，源语言在首位）
     * @return string[]
     */
    public static function langs(): array
    {
        $codes = [];
        foreach (self::registry() as $code => $item) {
            if ($item["enabled"]) {
                $codes[] = $code;
            }
        }
        return $codes;
    }

    /**
     * 需要翻译的目标语言 = 启用的非源语言。
     * 停用的语言不再收集 miss、不再投递给翻译插件——GitHub #887 要的就是这个。
     * @return string[]
     */
    public static function targets(): array
    {
        return array_values(array_diff(self::langs(), [self::SOURCE]));
    }

    /**
     * 全部已注册语言码（含停用）。停用只是不对外提供，已有译文照常保留、随时可再启用。
     * @return string[]
     */
    public static function registered(): array
    {
        return array_keys(self::registry());
    }

    /**
     * 是否已注册（含停用）——落库/重建缓存这类不对外的操作用它校验
     * @param string $code
     * @return bool
     */
    public static function known(string $code): bool
    {
        return isset(self::registry()[$code]);
    }

    /**
     * 是否可对外提供（已注册且启用）——解析请求语言、输出字典用它校验
     * @param string $code
     * @return bool
     */
    public static function acceptable(string $code): bool
    {
        return (self::registry()[$code]["enabled"] ?? false) === true;
    }

    /**
     * 显示名，未注册的语言原样返回语言码
     * @param string $code
     * @return string
     */
    public static function name(string $code): string
    {
        return (string)(self::registry()[$code]["name"] ?? $code);
    }

    /**
     * 交给 AI 翻译插件的目标语言描述
     * @param string $code
     * @return string
     */
    public static function promptName(string $code): string
    {
        return (string)(self::registry()[$code]["prompt"] ?? self::name($code));
    }

    /**
     * 语言码的 BCP-47 写法：站内一律小写存放（zh-cn / pt-br），对外要把地区子标签大写。
     * 用在 <html lang="…">、hreflang，以及切换器里给每种语言标注代码的模板。
     * @param string $code
     * @return string
     */
    public static function tag(string $code): string
    {
        $parts = explode("-", $code);
        if (isset($parts[1])) {
            $parts[1] = strtoupper($parts[1]);
        }
        return implode("-", $parts);
    }

    /**
     * 前台语言切换器数据源。模板里直接 #{foreach lang_menu() as $l} 即可，
     * 站长加了语言、停用了语言都不用再改模板。
     *
     * 每项：code 语言码 / name 显示名 / short 角标 / tag BCP-47 写法 / active 是否当前语言
     * @return array<int, array{code:string,name:string,short:string,tag:string,active:bool}>
     */
    public static function menu(): array
    {
        $current = self::get();
        $menu = [];
        foreach (self::registry() as $code => $item) {
            if (!$item["enabled"]) {
                continue;
            }
            $menu[] = [
                "code" => $code,
                "name" => $item["name"],
                "short" => $item["short"],
                "tag" => self::tag($code),
                "active" => $code === $current,
            ];
        }
        return $menu;
    }

    /**
     * 保存注册表。只有后台语言管理会调用它。
     *
     * @param array $rows [[code,name,prompt,enabled], ...]
     * @return array 规范化后的注册表
     */
    public static function saveRegistry(array $rows): array
    {
        $clean = [];
        foreach (self::normalizeRegistry($rows) as $item) {
            $clean[] = [
                "code" => $item["code"],
                "name" => $item["name"],
                "prompt" => $item["prompt"],
                "enabled" => $item["enabled"],
            ];
        }

        \App\Model\Config::put(self::REGISTRY_CONFIG, json_encode($clean, JSON_UNESCAPED_UNICODE));
        self::$registry = null;

        return self::registry();
    }

    /**
     * 解析当前请求语言：cookie > Accept-Language > zh-cn
     * @return string
     */
    public static function detect(): string
    {
        if (self::$lang !== null) {
            return self::$lang;
        }

        $cookie = self::normalizeCode((string)($_COOKIE[self::COOKIE] ?? ""));
        if ($cookie !== "" && self::acceptable($cookie)) {
            return self::$lang = $cookie;
        }

        return self::$lang = self::fromAcceptLanguage((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ""));
    }

    /**
     * @param string $header
     * @return string
     */
    public static function fromAcceptLanguage(string $header): string
    {
        if ($header === "") {
            return self::SOURCE;
        }

        $best = null;
        $bestQ = -1.0;
        foreach (explode(",", $header) as $part) {
            $seg = array_map("trim", explode(";", $part));
            $tag = strtolower($seg[0] ?? "");
            if ($tag === "") {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($seg, 1) as $param) {
                if (str_starts_with($param, "q=")) {
                    $q = (float)substr($param, 2);
                }
            }
            $mapped = self::mapTag($tag);
            if ($mapped !== null && $q > $bestQ) {
                $best = $mapped;
                $bestQ = $q;
            }
        }
        return $best ?? self::SOURCE;
    }

    /**
     * @param string $tag
     * @return string|null
     */
    private static function mapTag(string $tag): ?string
    {
        //整串精确命中优先：站长加了 pt-br，浏览器报 pt-br 就直接用它
        if (self::acceptable($tag)) {
            return $tag;
        }

        if (str_starts_with($tag, "zh")) {
            //zh-tw / zh-hk / zh-mo / zh-hant 归繁体，其余中文归简体；停用了就回落到另一边
            foreach (["tw", "hk", "mo", "hant"] as $t) {
                if (str_contains($tag, $t)) {
                    return self::acceptable("zh-tw") ? "zh-tw" : (self::acceptable("zh-cn") ? "zh-cn" : null);
                }
            }
            return self::acceptable("zh-cn") ? "zh-cn" : null;
        }

        //其余按主标签匹配：浏览器报 ko-kr 命中站点的 ko，报 pt 命中站点的 pt-br
        $primary = explode("-", $tag)[0];
        foreach (self::registry() as $code => $item) {
            if (!$item["enabled"]) {
                continue;
            }
            if ($code === $primary || str_starts_with($code, $primary . "-")) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @return string
     */
    public static function get(): string
    {
        return self::$lang ?? self::detect();
    }

    /**
     * 仅供测试/特殊流程重置进程内状态
     * @param string|null $lang
     * @return void
     */
    public static function reset(?string $lang = null): void
    {
        self::$lang = $lang;
        self::$dict = null;
        self::$reverse = null;
        self::$registry = null;
        self::$miss = [];
    }

    /**
     * 翻译入口：命中返译文，miss 收集后返回原文，永不抛错
     * @param string $source
     * @param string $scene tpl/js/api/dyn
     * @return string
     */
    public static function trans(string $source, string $scene = "tpl"): string
    {
        if ($source === "" || self::get() === self::SOURCE) {
            return $source;
        }

        if (self::$dict === null) {
            self::$dict = self::loadDict(self::get());
        }

        if (isset(self::$dict[$source])) {
            return self::$dict[$source];
        }

        //兼容首尾空格：词条以去空格形式入库，命中后按原样保留两侧空白
        $trimmed = trim($source);
        if ($trimmed !== $source && $trimmed !== "" && isset(self::$dict[$trimmed])) {
            return str_replace($trimmed, self::$dict[$trimmed], $source);
        }

        //带标签的文案（后台常把图标写进配置值，如 <i class="…"></i> 推荐）：整串查不到时，
        //先看文本片段是不是已有现成译文，能全部命中就直接复用，省掉一次翻译调用
        //长文本(商品详情/公告这类富文本)不进 runtime 字典文件——否则文件体积随商品数增长，
        //而它每请求都要 include。改为按 (hash,lang) 唯一索引点查，一个页面最多命中一两条。
        if (mb_strlen($source) > self::MAX_SOURCE_LEN) {
            $long = self::longText($source, self::get());
            if ($long !== null) {
                return $long;
            }
        }

        if (str_contains($source, "<")) {
            $translated = self::transMarkup($source);
            if ($translated !== null) {
                return $translated;
            }
        }

        self::collect($source, $scene);
        return $source;
    }

    /**
     * 长文本译文按需点查（走 uk_hash_lang 唯一索引），请求内缓存，任何异常降级为"没译文"。
     * @param string $source
     * @param string $lang
     * @return string|null
     */
    private static function longText(string $source, string $lang): ?string
    {
        $key = $lang . ":" . md5($source);
        if (array_key_exists($key, self::$longCache)) {
            return self::$longCache[$key];
        }
        //正常页面只会命中一两条，设个上限纯粹是防御异常调用把内存吃满
        if (count(self::$longCache) >= 50) {
            return null;
        }

        $text = null;
        try {
            $value = DB::table(self::TABLE)
                ->where("hash", md5($source))
                ->where("lang", $lang)
                ->value("text");
            if (is_string($value) && $value !== "") {
                $text = $value;
                //记下译文：同一请求里模板可能对已翻好的内容再调一次 lang()
                self::$longReverse[$value] = true;
            }
        } catch (\Throwable $e) {
        }

        return self::$longCache[$key] = $text;
    }

    /**
     * 复用已有词条翻译 HTML 片段里的文本节点，标签与属性不动。
     * 这里**只读词库、不收集**：一段都没命中时返回 null，由 trans() 把整串交给
     * 翻译插件的富文本路径整段翻译（那样才有上下文），避免同一句被拆成碎片入库。
     *
     * @param string $source
     * @return string|null
     */
    private static function transMarkup(string $source): ?string
    {
        //按标签切开，捕获组让标签本身也留在结果里
        $parts = preg_split('/(<[^>]*>)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || count($parts) < 2) {
            return null;
        }

        $hit = false;
        $pending = false;
        foreach ($parts as $i => $part) {
            if ($part === "" || $part[0] === "<") {
                continue;
            }
            $text = trim($part);
            if ($text === "" || !preg_match('/\p{Han}/u', $text)) {
                continue;
            }
            if (isset(self::$dict[$text])) {
                $parts[$i] = str_replace($text, self::$dict[$text], $part);
                $hit = true;
                continue;
            }
            $pending = true;
        }

        //还有片段没译出来就整串交给富文本路径，避免半中半英
        return ($hit && !$pending) ? implode("", $parts) : null;
    }

    /**
     * miss 收集（含过滤），供 trans() 与前端上报端点共用
     * @param string $source
     * @param string $scene
     * @return void
     */
    public static function collect(string $source, string $scene = "js"): void
    {
        if (count(self::$miss) >= self::MAX_MISS_PER_REQUEST) {
            return;
        }
        $source = trim($source);
        if ($source === "" || isset(self::$miss[$source])) {
            return;
        }
        //带真实标签的富文本(商品详情、公告)放宽到 MAX_MARKUP_SOURCE_LEN，普通文案仍是 500。
        //前端上报(js)不享受放宽：/user/api/lang/report 是免登录端点，放宽等于让匿名访客
        //按 50 条/次往词库和翻译队列灌超长文本，白烧翻译额度。
        $isMarkup = $scene !== "js" && preg_match('/<[a-zA-Z\/!]/', $source) === 1;
        //只收集含汉字且长度可控的文本
        if (mb_strlen($source) > ($isMarkup ? self::MAX_MARKUP_SOURCE_LEN : self::MAX_SOURCE_LEN)
            || !preg_match('/\p{Han}/u', $source)) {
            return;
        }

        //只拦「被二次转义的 HTML 碎片」（如 &quot;&amp;gt;未启用）——这种翻不出有意义的
        //结果，只会污染词库。正常带标签的富文本（公告、商品详情）要放行，交给翻译插件的
        //富文本路径整段处理，标签会被原样保留。
        //富文本里出现 &amp; / &#39; 是编辑器的正常产物，不是二次转义；对它只认「标签定界符
        //本身也被转义」这个真正的二次转义特征，否则站长写一句「A & B」整段详情就被静默丢掉。
        if (preg_match($isMarkup ? '/&(?:lt|gt);/' : '/&(?:amp|quot|lt|gt|#\d+);/', $source)) {
            return;
        }

        //防翻译回环：文本本身就是当前语言的某条译文时（接口 msg 已翻译后又被前端二次翻译），
        //日文/繁体译文同样命中 \p{Han}，若不拦截会被当成新的中文原文入库并浪费翻译额度
        if (self::$dict === null && self::get() !== self::SOURCE) {
            self::$dict = self::loadDict(self::get());
        }
        if (self::$dict) {
            if (self::$reverse === null) {
                self::$reverse = array_fill_keys(array_values(self::$dict), true);
            }
            if (isset(self::$reverse[$source])) {
                return;
            }
        }
        //长文本的译文不在 $dict 里（见 longText），单独拦一道：默认主题 Cartoon 的模板会对
        //控制器已翻好的商品详情再调一次 lang()，不拦就会把译文当成新原文入库并浪费翻译额度
        if (isset(self::$longReverse[$source])) {
            return;
        }

        self::$miss[$source] = $scene;

        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function () {
                Lang::flushMiss();
            });
        }
    }

    /**
     * 请求尾批量落库 + 触发 LANG_MISS 钩子；任何异常静默（安装页/DB故障场景）
     * @return void
     */
    public static function flushMiss(): void
    {
        if (self::$miss === []) {
            return;
        }
        $miss = self::$miss;
        self::$miss = [];

        $targets = self::targets();

        try {
            $now = date("Y-m-d H:i:s");
            $rows = [];
            foreach ($miss as $source => $scene) {
                $hash = md5($source);
                foreach ($targets as $lang) {
                    $rows[] = [
                        "hash" => $hash,
                        "source" => $source,
                        "lang" => $lang,
                        "text" => null,
                        "scene" => (string)$scene,
                        "status" => 0,
                        "create_time" => $now,
                        "update_time" => $now,
                    ];
                }
            }
            DB::table(self::TABLE)->insertOrIgnore($rows);
        } catch (\Throwable $e) {
            return;
        }

        try {
            if (function_exists("hook")) {
                $sources = array_keys($miss);
                hook(\App\Consts\Hook::LANG_MISS, $sources, $targets);
            }
        } catch (\Throwable $e) {
        }
    }

    // ---------------- 翻译入库 interface（插件/管理页/导入器共用） ----------------

    /**
     * 单条写入(译文)，status: 1=机翻 2=人工
     * @param string $source
     * @param string $lang
     * @param string|null $text
     * @param int $status
     * @param string $scene
     * @return void
     */
    public static function store(string $source, string $lang, ?string $text, int $status = 1, string $scene = "dyn"): void
    {
        if (!self::known($lang) || $lang === self::SOURCE) {
            return;
        }
        $now = date("Y-m-d H:i:s");
        DB::table(self::TABLE)->updateOrInsert(
            ["hash" => md5($source), "lang" => $lang],
            ["source" => $source, "text" => $text, "status" => $status, "scene" => $scene, "update_time" => $now, "create_time" => $now]
        );
    }

    /**
     * 批量写入并重建受影响语言的缓存文件
     * @param array $rows [[source,lang,text,status?,scene?], ...]
     * @return int 成功条数
     */
    public static function storeBatch(array $rows): int
    {
        $count = 0;
        $langs = [];
        foreach ($rows as $row) {
            $source = (string)($row["source"] ?? $row[0] ?? "");
            $lang = (string)($row["lang"] ?? $row[1] ?? "");
            $text = $row["text"] ?? $row[2] ?? null;
            $status = (int)($row["status"] ?? $row[3] ?? 1);
            $scene = (string)($row["scene"] ?? $row[4] ?? "dyn");
            if ($source === "" || $text === null || $text === "") {
                continue;
            }
            try {
                self::store($source, $lang, (string)$text, $status, $scene);
                $langs[$lang] = true;
                $count++;
            } catch (\Throwable $e) {
            }
        }
        foreach (array_keys($langs) as $lang) {
            self::rebuild($lang);
        }
        return $count;
    }

    /**
     * 扩展自带词包的扫描根目录：{根}/{扩展名}/Lang/{语言}.json
     */
    private const EXTENSION_ROOTS = [
        "/app/Plugin" => "插件",
        "/app/Pay" => "支付插件",
        "/app/View/User/Theme" => "模板",
    ];

    /**
     * 扫描并导入插件/支付插件/模板自带的词包。
     *
     * 约定：`app/Plugin/{插件名}/Lang/{zh-tw,en,ja}.json`，格式与主词包相同
     * （键=简体中文原文，值=译文）。入库时 scene 记为 `ext:{扩展名}`，
     * 便于在翻译管理页按来源筛选，也便于卸载扩展时清理。
     *
     * 用 insertOrIgnore 语义：站长手工改过的译文与翻译插件回写的机翻结果都不会被覆盖。
     * 按文件指纹跳过没变过的包，所以可以放心反复调用。
     *
     * @param bool $force 忽略指纹，强制重新导入
     * @return array{packs:int, imported:int, extensions:string[]}
     */
    public static function scanExtensionPacks(bool $force = false): array
    {
        $result = ["packs" => 0, "imported" => 0, "extensions" => []];
        $targets = self::targets();

        $stateFile = BASE_PATH . "/runtime/lang/packs.json";
        $state = [];
        if (!$force && is_file($stateFile)) {
            $decoded = json_decode((string)@file_get_contents($stateFile), true);
            $state = is_array($decoded) ? $decoded : [];
        }

        $seen = [];
        $touched = [];
        foreach (array_keys(self::EXTENSION_ROOTS) as $root) {
            $dir = BASE_PATH . $root;
            if (!is_dir($dir)) {
                continue;
            }
            foreach ((array)glob($dir . "/*/Lang", GLOB_ONLYDIR) as $langDir) {
                $extension = basename(dirname((string)$langDir));
                foreach ($targets as $lang) {
                    $file = $langDir . "/" . $lang . ".json";
                    if (!is_file($file)) {
                        continue;
                    }
                    $result["packs"]++;
                    $key = $root . "/" . $extension . "/" . $lang;
                    $fingerprint = (string)@md5_file($file);
                    $seen[$key] = $fingerprint;
                    if (($state[$key] ?? null) === $fingerprint) {
                        continue;   //没变过，跳过
                    }

                    $stored = self::importPackFile($file, $lang, "ext:" . $extension, $force);
                    if ($stored > 0) {
                        $result["imported"] += $stored;
                        $touched[$lang] = true;
                        $result["extensions"][$extension] = true;
                    }
                }
            }
        }

        foreach (array_keys($touched) as $lang) {
            self::rebuild($lang);
        }

        $dir = dirname($stateFile);
        if (is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir)) {
            @file_put_contents($stateFile, json_encode($seen, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }

        $result["extensions"] = array_keys($result["extensions"]);
        return $result;
    }

    /**
     * 导入单个词包文件，返回写入条数。不在这里 rebuild，交给调用方合并处理。
     *
     * 覆盖策略：
     *   - 库里没有         → 插入，status=2
     *   - 库里是机翻(1)     → 用词包覆盖并升级为 2；扩展作者写的译文比机翻可靠
     *   - 库里是人工确认(2) → 不动，保护站长在后台改过的译法
     *   - $force=true      → 额外覆盖同一 scene 的行（该扩展自己上一版留下的译文）
     *
     * @param string $file
     * @param string $lang
     * @param string $scene
     * @param bool $force
     * @return int
     */
    public static function importPackFile(string $file, string $lang, string $scene, bool $force = false): int
    {
        if (!self::known($lang) || $lang === self::SOURCE) {
            return 0;
        }
        $pack = json_decode((string)@file_get_contents($file), true);
        if (!is_array($pack)) {
            return 0;
        }

        $now = date("Y-m-d H:i:s");
        $scene = mb_substr($scene, 0, 32);
        $rows = [];
        foreach ($pack as $source => $text) {
            //兼容 {"原文": {"text": "译文"}} 与 {"原文": "译文"} 两种写法
            if (is_array($text)) {
                $text = $text["text"] ?? "";
            }
            if (!is_string($text) || trim($text) === "" || (string)$source === "") {
                continue;
            }
            $rows[] = [
                "hash" => md5((string)$source),
                "source" => (string)$source,
                "lang" => $lang,
                "text" => trim($text),
                "scene" => $scene,
                "status" => 2,      //随扩展分发，视为人工确认
                "create_time" => $now,
                "update_time" => $now,
            ];
        }
        if ($rows === []) {
            return 0;
        }

        $stored = 0;
        try {
            foreach (array_chunk($rows, 500) as $chunk) {
                $stored += (int)DB::table(self::TABLE)->insertOrIgnore($chunk);
            }

            //已存在的行：机翻可以被词包顶掉；force 时连本扩展上一版的译文一起刷新
            foreach (array_chunk($rows, 200) as $chunk) {
                foreach ($chunk as $row) {
                    //不加 text != 条件：译文碰巧一致的行也要认领 scene，
                    //否则卸载扩展时清理不到它们（MySQL 对无变化的 UPDATE 返回 0，计数不会虚高）
                    $query = DB::table(self::TABLE)
                        ->where("hash", $row["hash"])
                        ->where("lang", $lang);
                    $query = $force
                        ? $query->where(function ($q) use ($scene) {
                            $q->where("status", 1)->orWhere("scene", $scene);
                        })
                        : $query->where("status", 1);
                    $stored += (int)$query->update([
                        "text" => $row["text"],
                        "status" => 2,
                        "scene" => $scene,
                        "update_time" => $row["update_time"],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            return $stored;
        }
        return $stored;
    }

    /**
     * 删除某个扩展带来的词条（卸载扩展时调用），不影响其它来源
     *
     * @param string $extension
     * @return int
     */
    public static function forgetExtension(string $extension): int
    {
        if ($extension === "") {
            return 0;
        }
        try {
            $deleted = (int)DB::table(self::TABLE)
                ->where("scene", mb_substr("ext:" . $extension, 0, 32))
                ->delete();
        } catch (\Throwable $e) {
            return 0;
        }
        if ($deleted > 0) {
            self::rebuild();
        }
        //指纹里也要抹掉，否则重新安装时会被当成"没变过"而跳过
        $stateFile = BASE_PATH . "/runtime/lang/packs.json";
        if (is_file($stateFile)) {
            $state = json_decode((string)@file_get_contents($stateFile), true);
            if (is_array($state)) {
                foreach (array_keys($state) as $key) {
                    if (str_contains((string)$key, "/" . $extension . "/")) {
                        unset($state[$key]);
                    }
                }
                @file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
        }
        return $deleted;
    }

    /**
     * 重建语言缓存文件（临时文件+rename 原子替换），并推进字典版本号
     * @param string|null $lang null=全部目标语言
     * @return void
     */
    public static function rebuild(?string $lang = null): void
    {
        $langs = $lang === null ? array_diff(self::registered(), [self::SOURCE]) : [$lang];
        $dir = BASE_PATH . "/runtime/lang";
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        foreach ($langs as $l) {
            if (!self::known($l) || $l === self::SOURCE) {
                continue;
            }
            try {
                $map = [];
                DB::table(self::TABLE)
                    ->where("lang", $l)
                    ->whereNotNull("text")
                    ->where("text", "!=", "")
                    ->orderBy("id")
                    ->chunk(2000, function ($items) use (&$map) {
                        foreach ($items as $item) {
                            $source = (string)$item->source;
                            //长文本走 trans() 的按需点查，不进字典文件：既避免文件随商品数膨胀，
                            //也避免被打进前端 i18n 包（浏览器根本用不到整段商品详情）
                            if (mb_strlen($source) > self::MAX_SOURCE_LEN) {
                                continue;
                            }
                            $map[$source] = (string)$item->text;
                        }
                    });

                $file = $dir . "/{$l}.php";
                $tmp = $file . "." . uniqid("", true) . ".tmp";
                file_put_contents($tmp, "<?php\nreturn " . var_export($map, true) . ";\n", LOCK_EX);
                rename($tmp, $file);
                if (function_exists("opcache_invalidate")) {
                    @opcache_invalidate($file, true);
                }
            } catch (\Throwable $e) {
            }
        }

        //推进版本号：dict 端点 URL 随之变化，浏览器强缓存自动失效
        try {
            \App\Model\Config::put("lang_version", substr(md5((string)microtime(true)), 0, 8));
        } catch (\Throwable $e) {
        }

        //当前请求内的字典可能已过期
        self::$dict = null;
        self::$reverse = null;
        self::$longCache = [];
        self::$longReverse = [];
    }

    /**
     * 翻译接口返回列表里的展示字段（插件名/简介、支付方式名、应用商店条目等）。
     *
     * 这些文案来自插件 Info.php、支付配置或远端商店，属于动态内容，静态词包收不到；
     * 走 trans() 后未命中会照常进 LANG_MISS 队列，由翻译插件补齐。
     * 只改展示字段，plugin_key / id 之类的标识不动。
     *
     * @param array $rows 列表，元素为数组
     * @param string[] $fields 字段名，支持 "info.name" 这样的点号路径
     * @param string $scene
     * @return array
     */
    public static function transList(array $rows, array $fields, string $scene = "dyn"): array
    {
        if (self::get() === self::SOURCE || $rows === [] || $fields === []) {
            return $rows;
        }
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($fields as $field) {
                $rows[$i] = self::transPath($rows[$i], explode(".", $field), $scene);
            }
        }
        return $rows;
    }

    /**
     * 按路径定位并翻译：字符串直接翻，数组（如支付方式的 options）逐项翻。
     *
     * @param array $row
     * @param string[] $path
     * @param string $scene
     * @return array
     */
    private static function transPath(array $row, array $path, string $scene): array
    {
        $key = array_shift($path);
        if (!array_key_exists($key, $row)) {
            return $row;
        }
        if ($path !== []) {
            if (is_array($row[$key])) {
                $row[$key] = self::transPath($row[$key], $path, $scene);
            }
            return $row;
        }
        if (is_string($row[$key])) {
            $row[$key] = self::trans($row[$key], $scene);
        } elseif (is_array($row[$key])) {
            foreach ($row[$key] as $k => $v) {
                if (is_string($v)) {
                    $row[$key][$k] = self::trans($v, $scene);
                }
            }
        }
        return $row;
    }

    /**
     * 翻译「对象数组」里的指定键：商品 tags 的 text、widget 的 cn/placeholder/error 都是这种形状
     * （[{"text":"限时特惠","color":"orange"}, ...]），transList/transPath 只能处理字符串数组，覆盖不到。
     *
     * 入参允许是数据库原样的 JSON 字符串或已解码数组，返回与入参同型，不改变调用方的数据契约。
     * 只翻列出的键，name/color/regex/type 这类标识与样式值一概不动。
     *
     * @param mixed $value JSON 字符串或数组
     * @param string[] $keys 要翻译的键
     * @param string $scene
     * @return mixed
     */
    public static function transObjectList(mixed $value, array $keys, string $scene = "dyn"): mixed
    {
        if (self::get() === self::SOURCE || $value === null || $value === "" || $keys === []) {
            return $value;
        }

        $wasJson = is_string($value);
        $list = $wasJson ? json_decode($value, true) : $value;
        if (!is_array($list) || $list === []) {
            return $value;
        }

        $changed = false;
        foreach ($list as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($keys as $key) {
                if (isset($row[$key]) && is_string($row[$key]) && $row[$key] !== "") {
                    $translated = self::trans($row[$key], $scene);
                    if ($translated !== $row[$key]) {
                        $list[$i][$key] = $translated;
                        $changed = true;
                    }
                }
            }
        }

        if (!$changed) {
            return $value;
        }
        //JSON 进 JSON 出：中文不转义，与 Commodity 模型写入时的编码保持一致
        return $wasJson ? (string)json_encode($list, JSON_UNESCAPED_UNICODE) : $list;
    }

    /**
     * 字典版本号（用于 dict 端点缓存 bust）
     * @return string
     */
    public static function version(): string
    {
        try {
            return (string)(\App\Model\Config::get("lang_version") ?: "0");
        } catch (\Throwable $e) {
            return "0";
        }
    }

    /**
     * @param string $lang
     * @return array source => text
     */
    public static function dict(string $lang): array
    {
        if (!self::known($lang) || $lang === self::SOURCE) {
            return [];
        }
        //前端 i18n() 只翻短 UI 文案，从不整段翻商品详情/公告——那些富文本走服务端 trans()，
        //浏览器根本用不到。放宽富文本上限(MAX_MARKUP_SOURCE_LEN)后若不在此拦一道，它们会被
        //打进每个访客都要下载的 i18n 包(现已 450KB)。服务端热路径直连 loadDict()，不受影响。
        $dict = [];
        foreach (self::loadDict($lang) as $source => $text) {
            if (mb_strlen((string)$source) <= self::MAX_SOURCE_LEN) {
                $dict[$source] = $text;
            }
        }
        return $dict;
    }

    /**
     * @param string $lang
     * @return array
     */
    private static function loadDict(string $lang): array
    {
        $file = BASE_PATH . "/runtime/lang/{$lang}.php";
        if (!is_file($file)) {
            return [];
        }
        try {
            $dict = @include $file;
            return is_array($dict) ? $dict : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
