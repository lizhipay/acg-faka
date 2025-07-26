<?php
declare (strict_types=1);

namespace Kernel\Waf;

use Kernel\Component\Make;

class URISchemeFilter extends \HTMLPurifier_URIFilter
{

    use Make;

    /**
     * @var string
     */
    public $name = 'URISchemeFilter';


    /**
     * @var array|string[]
     */
    public array $whitelist = [
    ];

    /**
     * @param $uri
     * @param $config
     * @param $context
     * @return bool
     */
    public function filter(&$uri, $config, $context): bool
    {
        //不过滤所有网址
        return true;
    }
}