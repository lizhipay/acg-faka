<?php
declare (strict_types=1);

namespace Kernel\Waf;

use Kernel\Cache\Cache;
use Kernel\Component\Singleton;
use Kernel\Exception\RuntimeException;

class Firewall
{

    use Singleton;

    /**
     * 防火墙规则列表
     * @var array
     */
    private array $rule = [];


    /**
     * @var \HTMLPurifier|null
     */
    private ?\HTMLPurifier $HTMLPurifier = null;

    /**
     * @var Cache
     */
    private Cache $cache;

    public function __construct()
    {
        if (!is_dir(BASE_PATH . "/runtime/waf")) mkdir(BASE_PATH . "/runtime/waf", 0777, true);
        $this->cache = new Cache(BASE_PATH . "runtime/waf/PACKET", Cache::OPTIONS_STRING);
    }

    /**
     * @return void
     * @throws \HTMLPurifier_Exception
     * @throws \ReflectionException
     */
    private function HTMLPurifierInit(): void
    {
        if ($this->HTMLPurifier) {
            return;
        }

        $config = \HTMLPurifier_Config::createDefault();
        // 缓存配置，别装看不懂
        $config->set('Cache.SerializerPath', BASE_PATH . "/runtime/waf"); // 换成你服务器的路径
        $config->set('Cache.SerializerPermissions', 0755);
        $config->set('Cache.DefinitionImpl', 'Serializer');

        // 自定义 HTML 定义
        $config->set('HTML.DefinitionID', 'firewall.html');
        $config->set('HTML.DefinitionRev', 2);

        $config->set('Filter.Custom', [IgnoreStyleTagFilter::make()]);

        $config->getDefinition('URI')->addFilter(URISchemeFilter::make(), $config);


        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $def->addElement(
                'video',
                'Block',
                'Flow',
                'Common',
                array(
                    'poster' => 'URI',
                    'controls' => 'Bool#controls',
                    'width' => 'Text',
                    'height' => 'Text',
                    'src' => 'URI'
                )
            );
            $def->addElement(
                'source',
                'Block',
                'Flow',
                'Common',
                array(
                    'src*' => 'URI',
                    'type' => 'Text'
                )
            );
            $def->addElement(
                'marquee',
                'Block',
                'Flow',
                'Common',
                array(
                    'behavior' => 'Enum#scroll,slide,alternate',
                    'direction' => 'Enum#left,right,up,down',
                    'scrollamount' => 'Number',
                    'scrolldelay' => 'Number',
                    'loop' => 'Number',
                    'bgcolor' => 'Text',
                    'width' => 'Text',
                    'height' => 'Text',
                    'style' => 'Text'
                )
            );
            $def->addElement(
                'iframe',
                'Block',
                'Flow',
                'Common',
                array(
                    'src*' => 'URI',
                    'scrolling' => 'Enum#yes,no,auto',
                    'border' => 'Text',
                    'frameborder' => 'Text',
                    'framespacing' => 'Text',
                    'allowfullscreen' => 'Bool',
                    'sandbox' => 'Text',
                    'width' => 'Text',
                    'height' => 'Text',
                    'allow' => 'Text'
                )
            );
            $def->addAttribute('div', 'data-w-e-type', 'Text');
            $def->addAttribute('div', 'data-w-e-is-void', 'Bool');
            $def->addAttribute('a', 'target', 'Text');
            $def->addAttribute('img', 'width', 'Text');
            $def->addAttribute('img', 'height', 'Text');

            //富文本常用的HTML5语义标签，本身无脚本能力，纳入白名单，避免商品介绍/公告排版被拆壳(#775)
            foreach (['section', 'article', 'aside', 'nav', 'header', 'footer', 'main', 'figure', 'figcaption'] as $html5Block) {
                $def->addElement($html5Block, 'Block', 'Flow', 'Common');
            }
            $def->addElement('details', 'Block', 'Flow', 'Common', ['open' => 'Bool#open']);
            $def->addElement('summary', 'Block', 'Inline', 'Common');
            $def->addElement('mark', 'Inline', 'Inline', 'Common');
            $def->addElement('time', 'Inline', 'Inline', 'Common', ['datetime' => 'Text']);
            //仅允许纯装饰按钮，type限定button，防止嵌入页面表单被意外提交
            $def->addElement('button', 'Inline', 'Flow', 'Common', ['type' => 'Enum#button', 'disabled' => 'Bool#disabled']);
        }


        $this->HTMLPurifier = new \HTMLPurifier($config);
    }


    /**
     * @param callable $callable
     * @return void
     */
    public function check(callable $callable): void
    {
        $path = BASE_PATH . "/kernel/Waf/Rule";
        $this->rule["POST"] = json_decode(file_get_contents($path . "/post.json"), true);
        $this->rule["URL"] = json_decode(file_get_contents($path . "/url.json"), true);
        $this->rule["ARG"] = json_decode(file_get_contents($path . "/args.json"), true);
        $this->rule["COOKIE"] = json_decode(file_get_contents($path . "/cookie.json"), true);

        //GET过滤
        $getPara = urldecode(http_build_query($_GET));
        foreach ($this->rule["ARG"] as $key => $value) {
            if (preg_match("#" . $value[1] . "#i", $getPara)) {
                $callable($value);
                return;
            }
        }

        foreach ($this->rule["URL"] as $key => $value) {
            if (preg_match("#" . $value[1] . "#i", $getPara)) {
                $callable($value);
                return;
            }
        }

        //POST过滤
        $postPara = urldecode(http_build_query($_POST));
        foreach ($this->rule["POST"] as $key => $value) {
            if (preg_match("#" . $value[1] . "#i", $postPara)) {
                $callable($value);
                return;
            }
        }

        //COOKIE过滤
        $cookiePara = urldecode(http_build_query($_COOKIE));
        foreach ($this->rule["COOKIE"] as $key => $value) {
            if (preg_match("#" . $value[1] . "#i", $cookiePara)) {
                $callable($value);
                return;
            }
        }
    }


    /**
     * 输入在 PHP 解析请求时已经完成 URL 解码，这里绝不能再 urldecode 一次：
     * 用户字面输入的 %20/%2B/%25 等会被二次解码改写，全站所有表单字段
     * （查单密码、联系方式、自定义控件）都因此失真（#833）。
     * 统一走 xssKillerLiteral 的处理：照常用 HTMLPurifier 清 XSS，
     * 但保住裸 &，避免入库变成 &amp;。
     *
     * @param string $input
     * @return mixed
     * @throws \HTMLPurifier_Exception
     * @throws \ReflectionException
     */
    private function getCache(string $input): mixed
    {
        return $this->xssKillerLiteral($input);
    }

    /**
     * Filter a value that PHP has already decoded from the request body.
     *
     * Some payloads, such as card secrets, legitimately contain percent escape
     * text (for example "%0A") and ampersands. Passing those values through
     * getCache() would decode them a second time, while passing a bare
     * ampersand directly to HTMLPurifier would store it as "&amp;".
     *
     * @param mixed $input
     * @return mixed
     * @throws \HTMLPurifier_Exception
     * @throws \ReflectionException
     */
    public function xssKillerLiteral(mixed $input): mixed
    {
        if (is_array($input)) {
            $cleanedArray = [];
            foreach ($input as $key => $value) {
                $cleanedArray[$key] = $this->xssKillerLiteral($value);
            }
            return $cleanedArray;
        }
        if (!is_string($input)) {
            return $input;
        }

        $this->HTMLPurifierInit();
        // Shield one ampersand layer while HTMLPurifier removes unsafe markup,
        // then restore exactly that layer. Existing literal entities such as
        // "&amp;" remain literal text instead of being decoded.
        $escapedAmpersands = str_replace('&', '&amp;', $input);
        $cleaned = $this->HTMLPurifier->purify($escapedAmpersands);
        return str_replace('&amp;', '&', $cleaned);
    }

    /**
     * 旧版清洗管线（3.5.8 及以前）：对已解码的输入再做一次 urldecode，
     * 并且 HTMLPurifier 会把裸 & 实体化为 &amp;。
     * 仅供比对旧版本入库的历史数据（订单查询密码、账号密码哈希）时
     * 重放当年的转义形态使用，严禁用于新数据入库。
     *
     * @param mixed $input
     * @return mixed
     * @throws \HTMLPurifier_Exception
     * @throws \ReflectionException
     */
    public function xssKillerLegacy(mixed $input): mixed
    {
        if (is_array($input)) {
            $cleanedArray = [];
            foreach ($input as $key => $value) {
                $cleanedArray[$key] = $this->xssKillerLegacy($value);
            }
            return $cleanedArray;
        }
        if (!is_string($input)) {
            return $input;
        }

        $this->HTMLPurifierInit();
        return $this->HTMLPurifier->purify(urldecode(str_replace("+", "%2B", $input)));
    }

    /**
     * @param mixed $input
     * @return mixed
     * @throws RuntimeException
     * @throws \HTMLPurifier_Exception
     * @throws \ReflectionException
     */
    public function xssKiller(mixed $input): mixed
    {
        if (is_array($input)) {
            $cleanedArray = [];
            foreach ($input as $key => $value) {
                $key = $this->sanitizeKey($key); // 架构级兜底：中和键名注入字符（RCE/XSS），不改任何插件
                if (is_string($value)) {
                    //$cleanedArray[$key] = $this->HTMLPurifier->purify(urldecode(str_replace("+", "%2B", $value)));
                    $cleanedArray[$key] = $this->getCache($value);
                } elseif (is_array($value)) {
                    $cleanedArray[$key] = $this->xssKiller($value);
                } else {
                    $cleanedArray[$key] = $value;
                }
            }
            return $cleanedArray;
        } elseif (is_string($input)) {
            // return $this->HTMLPurifier->purify(urldecode(str_replace("+", "%2B", $input)));
            return $this->getCache($input);
        } else {
            return $input;
        }
    }


    /**
     * @param mixed $input
     * @param int $flags
     * @return mixed
     */
    public function filterContent(mixed $input, int $flags): mixed
    {
        if (is_null($input)) {
            return null;
        }

        if (is_array($input)) {
            $cleanedArray = [];
            foreach ($input as $key => $value) {
                $key = $this->sanitizeKey($key); // 架构级兜底：中和键名注入字符（RCE/XSS），不改任何插件
                if (is_string($value)) {
                    $cleanedArray[$key] = $this->filter($value, $flags);
                } elseif (is_array($value)) {
                    $cleanedArray[$key] = $this->filterContent($value, $flags);
                } else {
                    $cleanedArray[$key] = $value;
                }
            }
            return $cleanedArray;
        } else {
            return $this->filter($input, $flags);
        }
    }


    /**
     * @param mixed $content
     * @param int $flags
     * @return mixed
     */
    public function filter(mixed $content, int $flags): mixed
    {
        if (is_string($content)) {
            $content = trim($content);
            if ($flags & Filter::STRING_UNSIGNED) {
                $content = htmlspecialchars(strip_tags($content), ENT_QUOTES, 'UTF-8');
            }
        }
        if ($flags & Filter::INTEGER) {
            $content = (int)$content;
        }
        if ($flags & Filter::FLOAT) {
            $content = (float)$content;
        }
        if ($flags & Filter::BOOLEAN) {
            $content = (bool)$content;
        }
        return $content;
    }

    /**
     * 架构级安全兜底：中和数组"键名"里的注入字符。
     * 根因：WAF 历来只清洗数组的"值"、不清洗"键"——攻击者遂把 PHP 代码/HTML 藏进键名，
     * 经手写配置写入器逃逸单引号 => 配置文件 RCE；或经后台表格 innerHTML 渲染 => 存储型 XSS。
     * 合法键名（配置键 [A-Za-z0-9_]、SKU 规格名等人类文本）不含下列字符，故仅剔除：
     * 单引号/反斜杠（PHP 串逃逸）、尖括号/双引号（HTML 逃逸）、控制字符（含换行）。整数键原样保留。
     */
    private function sanitizeKey(mixed $key): mixed
    {
        if (!is_string($key)) {
            return $key;
        }
        return preg_replace('/[\x00-\x1F\x7F<>"\'\\\\]/', '', $key);
    }
}
