<?php
declare (strict_types=1);

namespace Kernel\Waf;

interface Filter
{
    const NORMAL = 1;
    const INTEGER = 2;
    const FLOAT = 4;
    const STRING_UNSIGNED = 8;
    const BOOLEAN = 16;
}