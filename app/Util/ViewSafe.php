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
                continue;
            }

            if ($ownerTrusted && self::ownerHtml($path, (string)$key)) {
                continue;
            }

            $data[$key] = self::text(
                in_array((string)$key, self::URL_KEYS, true) ? self::url($value) : $value
            );
        }

        return $data;
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
