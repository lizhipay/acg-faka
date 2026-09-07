<?php
declare (strict_types=1);

namespace Kernel\Util;

/**
 * 世界语言表：站长在后台「添加语言」时只能从这张表里选，不能手输语言代码，
 * 显示名与交给 AI 的目标语言描述也一并由这里给定，不让站长自己编。
 *
 * 这么定的原因：
 *  - 语言代码会直接拼进 runtime/lang/{code}.php 的文件名、Cookie 与前端 URL，
 *    自由输入意味着每个取值都要重新验一遍；
 *  - 显示名写错（比如把 ko 写成"日语"）前台就直接错给访客看；
 *  - 目标语言描述写得含糊，AI 翻出来的东西会跑偏，而站长没有理由懂怎么写提示词。
 *
 * 每项：[前台显示名(该语言自身的写法), 中文名, AI 目标语言描述?, 切换器角标?]
 *  - AI 目标语言描述省略时取「显示名（中文名）」，对模型足够明确；
 *  - 角标省略时取主标签大写（ko→KO、pt-br→PT）。
 */
final class LangCatalog
{
    public const LANGUAGES = [
        //东亚
        "zh-cn" => ["简体中文", "简体中文", "简体中文（中国大陆用语）", "简"],
        "zh-tw" => ["繁體中文", "繁体中文（台湾）", "繁體中文（台灣用語）", "繁"],
        "zh-hk" => ["繁體中文（香港）", "繁体中文（香港）", "繁體中文（香港用語，可用粵語詞彙）", "港"],
        "ja" => ["日本語", "日语", "日本語", "日"],
        "ko" => ["한국어", "韩语", null, "한"],
        "mn" => ["Монгол", "蒙古语"],

        //东南亚
        "vi" => ["Tiếng Việt", "越南语"],
        "th" => ["ไทย", "泰语"],
        "id" => ["Bahasa Indonesia", "印尼语"],
        "ms" => ["Bahasa Melayu", "马来语"],
        "fil" => ["Filipino", "菲律宾语"],
        "km" => ["ភាសាខ្មែរ", "高棉语"],
        "lo" => ["ລາວ", "老挝语"],
        "my" => ["မြန်မာဘာသာ", "缅甸语"],

        //南亚
        "hi" => ["हिन्दी", "印地语"],
        "bn" => ["বাংলা", "孟加拉语"],
        "ur" => ["اردو", "乌尔都语"],
        "ta" => ["தமிழ்", "泰米尔语"],
        "te" => ["తెలుగు", "泰卢固语"],
        "mr" => ["मराठी", "马拉地语"],
        "gu" => ["ગુજરાતી", "古吉拉特语"],
        "kn" => ["ಕನ್ನಡ", "卡纳达语"],
        "ml" => ["മലയാളം", "马拉雅拉姆语"],
        "pa" => ["ਪੰਜਾਬੀ", "旁遮普语"],
        "si" => ["සිංහල", "僧伽罗语"],
        "ne" => ["नेपाली", "尼泊尔语"],

        //中东、中亚、高加索
        "ar" => ["العربية", "阿拉伯语", "العربية（现代标准阿拉伯语）"],
        "he" => ["עברית", "希伯来语"],
        "fa" => ["فارسی", "波斯语"],
        "tr" => ["Türkçe", "土耳其语"],
        "ku" => ["Kurdî", "库尔德语"],
        "az" => ["Azərbaycan dili", "阿塞拜疆语"],
        "hy" => ["Հայերեն", "亚美尼亚语"],
        "ka" => ["ქართული", "格鲁吉亚语"],
        "kk" => ["Қазақ тілі", "哈萨克语"],
        "uz" => ["Oʻzbekcha", "乌兹别克语"],

        //英语及其地区变体
        "en" => ["English", "英语", "English", "EN"],
        "en-gb" => ["English (UK)", "英语（英国）", "British English"],

        //西欧、南欧
        "de" => ["Deutsch", "德语"],
        "fr" => ["Français", "法语"],
        "fr-ca" => ["Français (Canada)", "法语（加拿大）", "Français canadien"],
        "es" => ["Español", "西班牙语"],
        "es-419" => ["Español (Latinoamérica)", "西班牙语（拉美）", "Español latinoamericano"],
        "pt-pt" => ["Português (Portugal)", "葡萄牙语（葡萄牙）", "Português europeu"],
        "pt-br" => ["Português (Brasil)", "葡萄牙语（巴西）", "Português do Brasil"],
        "it" => ["Italiano", "意大利语"],
        "nl" => ["Nederlands", "荷兰语"],
        "ca" => ["Català", "加泰罗尼亚语"],
        "gl" => ["Galego", "加利西亚语"],
        "eu" => ["Euskara", "巴斯克语"],
        "el" => ["Ελληνικά", "希腊语"],
        "mt" => ["Malti", "马耳他语"],
        "ga" => ["Gaeilge", "爱尔兰语"],
        "cy" => ["Cymraeg", "威尔士语"],

        //北欧
        "sv" => ["Svenska", "瑞典语"],
        "da" => ["Dansk", "丹麦语"],
        "nb" => ["Norsk bokmål", "挪威语"],
        "fi" => ["Suomi", "芬兰语"],
        "is" => ["Íslenska", "冰岛语"],

        //中东欧、东欧
        "pl" => ["Polski", "波兰语"],
        "cs" => ["Čeština", "捷克语"],
        "sk" => ["Slovenčina", "斯洛伐克语"],
        "hu" => ["Magyar", "匈牙利语"],
        "ro" => ["Română", "罗马尼亚语"],
        "bg" => ["Български", "保加利亚语"],
        "sl" => ["Slovenščina", "斯洛文尼亚语"],
        "hr" => ["Hrvatski", "克罗地亚语"],
        "sr" => ["Српски", "塞尔维亚语"],
        "bs" => ["Bosanski", "波斯尼亚语"],
        "mk" => ["Македонски", "马其顿语"],
        "sq" => ["Shqip", "阿尔巴尼亚语"],
        "et" => ["Eesti", "爱沙尼亚语"],
        "lv" => ["Latviešu", "拉脱维亚语"],
        "lt" => ["Lietuvių", "立陶宛语"],
        "ru" => ["Русский", "俄语"],
        "uk" => ["Українська", "乌克兰语"],
        "be" => ["Беларуская", "白俄罗斯语"],

        //非洲
        "sw" => ["Kiswahili", "斯瓦希里语"],
        "am" => ["አማርኛ", "阿姆哈拉语"],
        "ha" => ["Hausa", "豪萨语"],
        "yo" => ["Yorùbá", "约鲁巴语"],
        "ig" => ["Igbo", "伊博语"],
        "zu" => ["isiZulu", "祖鲁语"],
        "xh" => ["isiXhosa", "科萨语"],
        "af" => ["Afrikaans", "南非荷兰语"],
        "so" => ["Soomaali", "索马里语"],
        "rw" => ["Kinyarwanda", "卢旺达语"],
        "mg" => ["Malagasy", "马达加斯加语"],

        //美洲及其他
        "ht" => ["Kreyòl ayisyen", "海地克里奥尔语"],
        "eo" => ["Esperanto", "世界语"],
    ];

    /**
     * @param string $code
     * @return bool
     */
    public static function has(string $code): bool
    {
        return isset(self::LANGUAGES[$code]);
    }

    /**
     * 取一门语言的元数据，未收录返回 null
     * @param string $code
     * @return array{code:string,name:string,zh:string,prompt:string,short:string}|null
     */
    public static function get(string $code): ?array
    {
        $row = self::LANGUAGES[$code] ?? null;
        if ($row === null) {
            return null;
        }

        $name = (string)$row[0];
        $zh = (string)$row[1];
        $prompt = isset($row[2]) && $row[2] !== null && $row[2] !== "" ? (string)$row[2] : "{$name}（{$zh}）";
        $short = isset($row[3]) && $row[3] !== null && $row[3] !== ""
            ? (string)$row[3]
            : strtoupper(substr(explode("-", $code)[0], 0, 2));

        return ["code" => $code, "name" => $name, "zh" => $zh, "prompt" => $prompt, "short" => $short];
    }

    /**
     * 整张表，按声明顺序（东亚 → 东南亚 → … → 美洲）
     * @return array<int, array{code:string,name:string,zh:string,prompt:string,short:string}>
     */
    public static function all(): array
    {
        $list = [];
        foreach (array_keys(self::LANGUAGES) as $code) {
            $list[] = self::get($code);
        }
        return $list;
    }
}
