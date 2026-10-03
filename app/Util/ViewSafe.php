<?php
declare(strict_types=1);

namespace App\Util;

final class ViewSafe
{
    private const RAW_PATHS = [
        'config.notice',
        'config.closed_message',
        'item.description',
        'toolbar.name',
    ];

    private const URL_KEYS = [
        'avatar', 'cover', 'icon', 'favicon', 'logo', 'url', 'src', 'image',
        'background_url', 'background_mobile_url', 'service_url', 'share_url',
        'pay_url', 'qrcode', 'web_site',
    ];

    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    private const OWNER_HTML_ROOTS = ['item', 'category', 'commodity'];

    private const OWNER_HTML_KEYS = ['name'];

    public static function escape(array $data): array
    {
        return self::walk($data, '');
    }

    private static function walk(array $data, string $prefix): array
    {
        $ownerTrusted = array_key_exists('owner', $data)
            && ($data['owner'] === null || (is_scalar($data['owner']) && (int)$data['owner'] === 0));

        foreach ($data as $key => $value) {
            $path = is_int($key) ? $prefix : ($prefix === '' ? (string)$key : $prefix . '.' . $key);

            if ($value instanceof Html) {
                $data[$key] = $value->raw;
                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::walk($value, $path);
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            if (in_array($path, self::RAW_PATHS, true)) {
                $data[$key] = self::neutralizeActive($value);
                continue;
            }

            if ($ownerTrusted && self::ownerHtml($path, (string)$key)) {
                $data[$key] = self::neutralizeActive($value);
                continue;
            }

            $data[$key] = self::text(
                in_array((string)$key, self::URL_KEYS, true) ? self::url($value) : $value
            );
        }

        return $data;
    }

    /**
     * RAW_PATHS 与 owner=0 名称是「不转义原样输出」的汇点。写入端已统一走 post()/RichHtml 净化管线，
     * 这里是**渲染侧兜底**：去掉可执行脚本、外部执行体标签、事件处理器与伪协议——即使将来某个写入端漏了
     * 净化，注入的 <script> 也到不了页面（否则会被 Csp::injectNonce 自动配发合法 nonce 而变成可执行，
     * 见 F-50）。**不动 <style>**：正文排版与插件卡片的作用域样式靠它，且已由 RichHtml/IgnoreStyleTagFilter
     * 做过 CSS 净化。
     */
    public static function neutralizeActive(string $value, bool $allowSafeIframe = false): string
    {
        if ($value === '' || strpos($value, '<') === false) {
            return $value;
        }
        //成对 / 残缺的 <script>，以及可载入外部执行体的标签
        $value = (string)preg_replace('#<script\b[^>]*>.*?</script\s*>#is', '', $value);
        if ($allowSafeIframe) {
            //可信正文（如商品描述）允许嵌视频 iframe：只留 src 为 https 的、去掉 srcdoc 与不合规的；
            //script/object/embed 照删。留下来的 iframe 上的 on*= 会在下方统一再剥一次。
            $value = self::sanitizeIframes($value);
            $value = (string)preg_replace('#<\s*/?\s*(?:script|object|embed)\b[^>]*>#i', '', $value);
        } else {
            $value = (string)preg_replace('#<\s*/?\s*(?:script|iframe|object|embed)\b[^>]*>#i', '', $value);
        }
        //事件处理器属性 on*=...（带双引号 / 单引号 / 裸值三种形态）
        //分隔符不限于空白：属性值的闭合引号、自闭合斜杠都可紧贴 on*，如 <img src="x"onerror=…>、
        //<svg/onload=…>。用定宽 lookbehind 认 [空白 / " '] 四种边界，命中即剥，不消费边界字符。
        $value = (string)preg_replace('#(?<=[\s/"\'])on[a-z0-9_\-]+\s*=\s*"[^"]*"#i', '', $value);
        $value = (string)preg_replace('#(?<=[\s/"\'])on[a-z0-9_\-]+\s*=\s*\'[^\']*\'#i', '', $value);
        $value = (string)preg_replace('#(?<=[\s/"\'])on[a-z0-9_\-]+\s*=\s*[^\s>]+#i', '', $value);
        //href/src/action 等属性里的伪协议
        $value = (string)preg_replace('#((?:href|src|xlink:href|action|formaction|poster)\s*=\s*["\']?)\s*(?:javascript|vbscript)\s*:#i', '$1#', $value);
        return $value;
    }

    /**
     * 可信正文里的 iframe 收口：只保留 src 为 https 的 iframe、丢掉 srcdoc（可内联 HTML 执行脚本）
     * 与没有合法 https src 的。保留 width/height/allowfullscreen 等播放器属性；on*= 由调用方统一再剥。
     */
    private static function sanitizeIframes(string $value): string
    {
        //只改开标签 <iframe ...>：src 为 https 才保留（顺带去掉 srcdoc），否则删掉开标签。对应的 </iframe>
        //与标签间的回退内容留给后续 on*/脚本净化处理，浏览器会忽略落单的 </iframe>。on*= 也在下方统一再剥。
        return (string)preg_replace_callback('#<iframe\b([^>]*)>#i', static function (array $m): string {
            $attrs = $m[1];
            if (!preg_match('#(?:^|\s)src\s*=\s*(?:"https://[^"]*"|\'https://[^\']*\'|https://[^\s>]+)#i', $attrs)) {
                return '';
            }
            $attrs = (string)preg_replace('#\ssrcdoc\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $attrs);
            return '<iframe' . $attrs . '>';
        }, $value);
    }

    private static function ownerHtml(string $path, string $key): bool
    {
        if (!in_array($key, self::OWNER_HTML_KEYS, true)) {
            return false;
        }
        return in_array(explode('.', $path)[0], self::OWNER_HTML_ROOTS, true);
    }

    private static function text(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function url(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $value;
        }

        $probe = strtolower(preg_replace('/[\x00-\x20]/', '', $trimmed) ?? '');

        if (!preg_match('#^([a-z][a-z0-9+.\-]*):#', $probe, $m)) {
            return $trimmed;
        }

        if (in_array($m[1], self::SAFE_SCHEMES, true)) {
            return $trimmed;
        }

        if (str_starts_with($probe, 'data:image/') && !str_starts_with($probe, 'data:image/svg')) {
            return $trimmed;
        }

        return '';
    }
}
