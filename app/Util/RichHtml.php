<?php
declare(strict_types=1);

namespace App\Util;

final class RichHtml
{
    //3：放行 https 嵌入视频 iframe（issue #982），旧版本缓存的净化结果作废
    private const VERSION = 3;

    private const CACHE_DIR = BASE_PATH . '/runtime/richhtml';

    private static ?\HTMLPurifier $purifier = null;

    public static function sanitize(string $html, bool $trusted): string
    {
        if (trim($html) === '') {
            return $html;
        }

        //可信内容（如 owner=0 自营商品描述）不走完整 HTMLPurifier，保留富排版与嵌入视频；但「对接上游」写进
        //来的商品在下游也是 owner=0，同样命中可信分支。所以可信路径仍先去活化一次：剥掉 <script>/<object>/<embed>、
        //on*= 事件、javascript: 伪协议；iframe 只留 src 为 https 的（去 srcdoc）。图片/链接/排版/样式/合规视频
        //均保留，恶意上游的存储型 XSS 则进不了下游店铺访客页。
        if ($trusted) {
            return ViewSafe::neutralizeActive($html, true);
        }

        $key = sha1(self::VERSION . '|' . $html);
        $cached = self::readCache($key);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $safe = (string)self::purifier()->purify($html);
        } catch (\Throwable $e) {
            return '';
        }

        //纵深防御：本 purifier 不挂 IgnoreStyleTagFilter，正常会剥掉 <style>；这里再兜底剥一次
        //残留的 <style> 与任何形态的 [STYLE-TAG…] 文本占位符，确保不可信正文（如分站公告 config.notice
        //∈ RAW_PATHS）不会把外连样式表/信标带出去，也不会被下游凭空还原成 <style>。
        $safe = (string)preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $safe);
        $safe = (string)preg_replace('/\[STYLE-TAG[^\]]*\].*?\[\/STYLE-TAG[^\]]*\]/is', '', $safe);
        //非 https 的 iframe 被 SafeIframe 剥掉 src 后会剩个空 <iframe></iframe>，清掉免得露出空白框
        $safe = (string)preg_replace('#<iframe\s*>\s*</iframe>#i', '', $safe);

        self::writeCache($key, $safe);
        return $safe;
    }

    public static function present(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        static $style = '<style>'
            . '.acg-rich{overflow-wrap:anywhere}'
            . '.acg-rich>:where(:first-child){margin-top:0}'
            . '.acg-rich>:where(:last-child){margin-bottom:0}'
            . '.acg-rich :where(h1,h2,h3,h4,h5,h6){margin:1.4em 0 .6em;line-height:1.35;font-weight:700}'
            . '.acg-rich :where(h1){font-size:1.5em}'
            . '.acg-rich :where(h2){font-size:1.3em}'
            . '.acg-rich :where(h3){font-size:1.15em}'
            . '.acg-rich :where(h4,h5,h6){font-size:1em}'
            . '.acg-rich :where(p,ul,ol,dl,table,pre,blockquote,figure){margin:0 0 1em}'
            . '.acg-rich :where(ul,ol){padding-left:1.6em}'

            . '.acg-rich :where(ul)>:where(li){list-style:disc}'
            . '.acg-rich :where(ol)>:where(li){list-style:decimal}'
            . '.acg-rich :where(li){margin:.25em 0}'
            . '.acg-rich :where(blockquote){padding:.6em 1em;border-left:3px solid color-mix(in srgb,currentColor 30%,transparent);background:color-mix(in srgb,currentColor 5%,transparent);border-radius:0 6px 6px 0}'
            . '.acg-rich :where(code){padding:.15em .4em;border-radius:4px;background:color-mix(in srgb,currentColor 9%,transparent);font-size:.9em;font-family:ui-monospace,Menlo,Consolas,monospace}'
            . '.acg-rich :where(pre){padding:1em;border-radius:8px;background:color-mix(in srgb,currentColor 7%,transparent);overflow-x:auto}'
            . '.acg-rich :where(pre code){padding:0;background:none;font-size:.88em}'
            . '.acg-rich :where(table){border-collapse:collapse;width:100%;display:block;overflow-x:auto}'
            . '.acg-rich :where(th,td){padding:.5em .75em;border:1px solid color-mix(in srgb,currentColor 18%,transparent)}'
            . '.acg-rich :where(th){background:color-mix(in srgb,currentColor 6%,transparent);font-weight:600;text-align:left}'
            . '.acg-rich :where(hr){margin:1.6em 0;border:0;border-top:1px solid color-mix(in srgb,currentColor 15%,transparent)}'
            . '.acg-rich :where(img){max-width:100%;height:auto;border-radius:6px}'
            . '.acg-rich :where(iframe){max-width:100%;border:0;border-radius:6px}'
            . '.acg-rich :where(a){text-decoration:underline;text-underline-offset:2px}'
            . '</style>';

        //描述里可能带 <style>：既有商户可能塞恶意 CSS，也有插件（如次元博客的「相关文档」卡片）通过
        //0x51 钩子把带样式的区块注入进 description。所以不能一律剥掉——改为与 IgnoreStyleTagFilter 同一套
        //CSS 净化：解码 CSS 转义后判定，命中会执行脚本/外连的构造（@import/expression/behavior/外链 url 等）
        //即整块丢弃，保留正常排版样式。这样插件区块照常显示，商户往描述里塞的危险 CSS 也被中和。
        //不再处理 [STYLE-TAG] 文本占位符：占位符已带进程盐、只在 WAF 的 purify 周期内存在，正文里
        //手写的无盐占位符是纯文本，绝不能在这里被凭空还原成 <style>（否则重开二阶还原漏洞）。
        //去掉任何残留的 [STYLE-TAG…] 文本占位符：正常内容到 present 时真 <style> 早被 WAF 还原(且带进程盐)，
        //这里出现占位符只可能是攻击者手写——一律删除。只删不还原，既不重开二阶还原漏洞，也免得垃圾文本露在描述里。
        $html = (string)preg_replace('/\[STYLE-TAG[^\]]*\].*?\[\/STYLE-TAG[^\]]*\]/is', '', $html);

        $html = (string)preg_replace_callback('/<style\b[^>]*>(.*?)<\/style>/is', static function (array $m): string {
            return '<style>' . \Kernel\Waf\IgnoreStyleTagFilter::sanitizeCss($m[1]) . '</style>';
        }, $html);

        return $style . '<div class="acg-rich">' . $html . '</div>';
    }

    private static function purifier(): \HTMLPurifier
    {
        if (self::$purifier) {
            return self::$purifier;
        }

        $cachePath = BASE_PATH . '/runtime/richhtml-purifier';
        if (!is_dir($cachePath)) {
            @mkdir($cachePath, 0755, true);
        }

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', $cachePath);
        $config->set('Cache.SerializerPermissions', 0755);
        $config->set('HTML.DefinitionID', 'acg.richhtml');
        $config->set('HTML.DefinitionRev', self::VERSION);

        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.Nofollow', true);

        //放行嵌入视频 iframe，但 src 只认 https（issue #982）：srcdoc/on*= 等危险属性由 HTMLPurifier
        //的 SafeIframe 自动剥除。外链域名白名单在写入侧由 WAF 的 URISchemeFilter 把关
        //（link_domain_filter 关闭=放行全部域名），与普通链接同一套口径，显示侧不重复做域名过滤。
        $config->set('HTML.SafeIframe', true);
        $config->set('URI.SafeIframeRegexp', '%^https://%');

        $config->set('Attr.EnableID', false);

        //与 WAF 同一套 CSS 放行口径（#952）。必须在 maybeGetRawHTMLDefinition() 之前：它会把配置定稿
        \Kernel\Waf\ModernCss::configure($config);

        if ($def = $config->maybeGetRawHTMLDefinition()) {
            foreach (['section', 'article', 'aside', 'header', 'footer', 'main', 'figure', 'figcaption'] as $tag) {
                $def->addElement($tag, 'Block', 'Flow', 'Common');
            }
            $def->addElement('details', 'Block', 'Flow', 'Common', ['open' => 'Bool#open']);
            $def->addElement('summary', 'Block', 'Inline', 'Common');
            $def->addElement('mark', 'Inline', 'Inline', 'Common');
            $def->addElement('time', 'Inline', 'Inline', 'Common', ['datetime' => 'Text']);
            $def->addAttribute('a', 'target', 'Text');
            $def->addAttribute('img', 'width', 'Text');
            $def->addAttribute('img', 'height', 'Text');
            //播放器常用属性：SafeIframe 的基础 iframe 不含它们，不补上全屏/自动播放等会失效
            $def->addAttribute('iframe', 'allowfullscreen', 'Bool');
            $def->addAttribute('iframe', 'allow', 'Text');
            $def->addAttribute('iframe', 'loading', 'Text');
        }

        self::$purifier = new \HTMLPurifier($config);
        \Kernel\Waf\ModernCss::install($config);
        return self::$purifier;
    }

    private static function cachePath(string $key): string
    {
        return self::CACHE_DIR . '/' . substr($key, 0, 2) . '/' . $key . '.html';
    }

    private static function readCache(string $key): ?string
    {
        $path = self::cachePath($key);
        if (!is_file($path)) {
            return null;
        }
        $data = @file_get_contents($path);
        return $data === false ? null : $data;
    }

    private static function writeCache(string $key, string $html): void
    {
        $path = self::cachePath($key);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $html) === false) {
            @unlink($tmp);
            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }
}
