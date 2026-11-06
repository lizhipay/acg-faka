<?php
declare(strict_types=1);

namespace App\Util;

use Kernel\Exception\JSONException;

/**
 * 分站 / 商户自填的客服入口（business.service_url / service_qq）。
 *
 * 写入端把常见写法规整成网址并拒收非 http(s) 链接（`javascript:` 之类过不了这一关）；
 * 输出端只认合格网址，旧数据里的脏值不会再被拿去当链接用。
 */
final class ServiceLink
{
    private const MAX_LENGTH = 255;

    /**
     * 店主填写的客服链接：留空表示不设置；`@用户名`、`t.me/用户名` 规整成 https://t.me/用户名。
     * @throws JSONException
     */
    public static function normalize(string $input): string
    {
        $value = trim($input);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^@([A-Za-z][A-Za-z0-9_]{3,31})$/', $value, $m)) {
            return 'https://t.me/' . $m[1];
        }
        if (preg_match('#^(?:t\.me|telegram\.me)/#i', $value)) {
            $value = 'https://' . $value;
        }
        if (mb_strlen($value) > self::MAX_LENGTH || self::safeUrl($value) === '') {
            throw new JSONException('客服链接格式不正确，请填写 http(s) 网址或 @Telegram 用户名');
        }
        return $value;
    }

    /**
     * 客服 QQ：留空或 5–12 位数字。
     * @throws JSONException
     */
    public static function qq(string $input): string
    {
        $value = trim($input);
        if ($value !== '' && !preg_match('/^[1-9]\d{4,11}$/', $value)) {
            throw new JSONException('客服 QQ 只能填写 5–12 位数字');
        }
        return $value;
    }

    /** 合格的 http(s) 网址原样返回，否则返回空串 */
    public static function safeUrl(?string $url): string
    {
        $url = trim((string)$url);
        if ($url === ''
            || preg_match('/[\x00-\x20\x7f\\\\"\'<>`]/', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            || parse_url($url, PHP_URL_HOST) === null
            || parse_url($url, PHP_URL_USER) !== null) {
            return '';
        }
        return $url;
    }

    /** 店铺的客服入口：优先客服链接，其次客服 QQ；都没填返回空串，由调用方决定回退到哪里 */
    public static function resolve(?string $url, ?string $qq): string
    {
        $link = self::safeUrl($url);
        if ($link !== '') {
            return $link;
        }
        $qq = trim((string)$qq);
        return preg_match('/^[1-9]\d{4,11}$/', $qq) ? 'https://wpa.qq.com/msgrd?v=1&uin=' . $qq : '';
    }
}
