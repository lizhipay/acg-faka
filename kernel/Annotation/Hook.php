<?php
declare(strict_types=1);

namespace Kernel\Annotation;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Hook
{

    /**
     * @var int
     */
    public int $point;

    /**
     * Hook constructor.
     * @param int $point
     */
    public function __construct(int $point)
    {
    }
}