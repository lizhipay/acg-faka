<?php
declare(strict_types=1);

namespace App\Util;

final class RichHtml
{
    private const VERSION = 1;

    private const CACHE_DIR = BASE_PATH . '/runtime/richhtml';

    private static ?\HTMLPurifier $purifier = null;

    public static function sanitize(string $html, bool $trusted): string
    {
        if ($trusted || trim($html) === '') {
            return $html;
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
            . '.acg-rich :where(a){text-decoration:underline;text-underline-offset:2px}'
            . '</style>';

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

        $config->set('Attr.EnableID', false);

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
        }

        return self::$purifier = new \HTMLPurifier($config);
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
