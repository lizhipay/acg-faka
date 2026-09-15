<?php
declare (strict_types=1);

namespace Kernel\Waf;

use Kernel\Component\Make;

class IgnoreStyleTagFilter extends \HTMLPurifier_Filter
{
    use Make;

    public $name = 'IgnoreStyleTagFilter';

    public function preFilter($html, $config, $context): array|string|null
    {
        return preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '[STYLE-TAG]$1[/STYLE-TAG]', $html);
    }

    public function postFilter($html, $config, $context): array|string|null
    {
        //把占位符还原成 <style> 时顺手净化 CSS：本过滤器让 <style> 绕过了 HTMLPurifier，
        //若原样放回，攻击者可在不可信富文本里注入会「执行脚本 / 外连信标」的 CSS。可信内容
        //（sanitize 的 $trusted 分支）根本不走本过滤器，所以这里一律按不可信处理。
        return preg_replace_callback('/\[STYLE-TAG\](.*?)\[\/STYLE-TAG\]/is', static function (array $m): string {
            return '<style>' . self::sanitizeCss($m[1]) . '</style>';
        }, (string)$html);
    }

    /**
     * 只保留基础排版样式，剔除会执行脚本或外连的 CSS 构造（这些在用户富文本里没有正当用途）。
     */
    private static function sanitizeCss(string $css): string
    {
        $css = (string)preg_replace('/@import\b[^;]*;?/i', '', $css);            // @import 拉外部样式表
        $css = (string)preg_replace('/expression\s*\(/i', 'void(', $css);       // IE expression() 执行 JS
        $css = (string)preg_replace('/behavior\s*:[^;]*;?/i', '', $css);        // IE behavior(.htc)
        $css = (string)preg_replace('/-moz-binding\b[^;]*;?/i', '', $css);      // XBL binding
        $css = (string)preg_replace('/(?:javascript|vbscript)\s*:/i', '', $css);// 伪协议
        //url(...)：仅放行 data:image、站内相对/根路径；外链(http/https/协议相对)置空，杜绝 CSS 外连信标
        return (string)preg_replace_callback('/url\(\s*(["\']?)(.*?)\1\s*\)/is', static function (array $u): string {
            $ref = trim($u[2]);
            $safe = $ref === ''
                || str_starts_with($ref, '/')
                || stripos($ref, 'data:image/') === 0
                || (!preg_match('#^[a-z][a-z0-9+.\-]*:#i', $ref) && !str_starts_with($ref, '//'));
            return $safe ? 'url(' . $u[1] . $ref . $u[1] . ')' : 'none';
        }, $css);
    }
}