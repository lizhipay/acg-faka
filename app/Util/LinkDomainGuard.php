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
            return self::$enabledCache = (Config::get(self::ENABLED_CONFIG) !== '0');
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
            foreach (preg_split('/[\r\n,]+/', (string)Config::get(self::WHITELIST_CONFIG)) ?: [] as $line) {
                $entries[] = $line;
            }
            $entries[] = (string)Config::get('domain');
            $entries[] = (string)Config::get('cname');
            $entries[] = (string)Config::get('callback_domain');
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
