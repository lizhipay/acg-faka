<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Config;

final class LinkDomainGuard
{
    public const ENABLED_CONFIG = 'link_domain_filter';

    public const WHITELIST_CONFIG = 'link_domain_whitelist';

    private static ?array $allowCache = null;

    private static ?bool $enabledCache = null;

    public static function enabled(): bool
    {
        if (self::$enabledCache !== null) {
            return self::$enabledCache;
        }
        try {
            //同 Csp::mode()：键不存在=默认开启，用只读缓存，别为缺键每请求拿一次排他锁
            return self::$enabledCache = ((Config::cached(self::ENABLED_CONFIG) ?? '') !== '0');
        } catch (\Throwable) {
            return self::$enabledCache = false;
        }
    }

    public static function allows(string $host): bool
    {
        return self::allowed(self::normalizeHost($host));
    }

    private static function normalizeHost(string $authority): string
    {
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $authority = substr($authority, $at + 1);
        }

        if (str_starts_with($authority, '[')) {
            $end = strpos($authority, ']');
            if ($end !== false) {
                return strtolower(substr($authority, 0, $end + 1));
            }
        }

        $colon = strpos($authority, ':');
        if ($colon !== false) {
            $authority = substr($authority, 0, $colon);
        }

        return strtolower(trim($authority, ".\t\n\r\0\x0B "));
    }

    private static function allowed(string $host): bool
    {
        foreach (self::allowList() as $entry) {
            if ($host === $entry || str_ends_with($host, '.' . $entry)) {
                return true;
            }
        }
        return false;
    }

    public static function allowList(): array
    {
        if (self::$allowCache !== null) {
            return self::$allowCache;
        }

        $entries = [];

        try {
            //只读缓存：这四个键任一不存在，Config::get() 都会每次提交拿一次排他锁再查库；
            //而"不存在"在这里就等于空，用 cached() 正好
            foreach (preg_split('/[\r\n,]+/', (string)(Config::cached(self::WHITELIST_CONFIG) ?? '')) ?: [] as $line) {
                $entries[] = $line;
            }
            $entries[] = (string)(Config::cached('domain') ?? '');
            $entries[] = (string)(Config::cached('cname') ?? '');
            $entries[] = (string)(Config::cached('callback_domain') ?? '');
        } catch (\Throwable) {
            return self::$allowCache = [];
        }

        try {
            $entries[] = (string)Client::getDomain();
        } catch (\Throwable) {
        }

        $clean = [];
        foreach ($entries as $entry) {
            foreach (preg_split('/[\r\n,\s]+/', (string)$entry) ?: [] as $piece) {
                $piece = trim((string)$piece);
                if ($piece === '') {
                    continue;
                }

                if (str_contains($piece, '://')) {
                    $piece = (string)parse_url($piece, PHP_URL_HOST);
                }
                $piece = ltrim($piece, '*.');
                $piece = self::normalizeHost($piece);
                if ($piece !== '') {
                    $clean[$piece] = true;
                }
            }
        }

        return self::$allowCache = array_keys($clean);
    }

    public static function resetCache(): void
    {
        self::$allowCache = null;
        self::$enabledCache = null;
    }
}
