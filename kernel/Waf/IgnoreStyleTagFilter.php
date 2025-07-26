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
        return preg_replace('/\[STYLE-TAG\](.*?)\[\/STYLE-TAG\]/is', '<style>$1</style>', $html);
    }
}