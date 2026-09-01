<?php
declare (strict_types=1);

namespace Kernel\Waf;

use App\Util\LinkDomainGuard;
use Kernel\Component\Make;

class URISchemeFilter extends \HTMLPurifier_URIFilter
{
    use Make;

    public $name = 'URISchemeFilter';

    public array $whitelist = [
    ];

    private static array $blocked = [];

    public static function reset(): void
    {
        self::$blocked = [];
    }

    public static function blocked(): ?string
    {
        return self::$blocked[0] ?? null;
    }

    public function filter(&$uri, $config, $context): bool
    {
        $host = isset($uri->host) ? strtolower(trim((string)$uri->host)) : '';

        if ($host === '') {
            return true;
        }

        if (!LinkDomainGuard::enabled() || LinkDomainGuard::allows($host)) {
            return true;
        }

        self::$blocked[] = $host;
        return false;
    }
}
