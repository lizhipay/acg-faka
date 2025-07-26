<?php
declare (strict_types=1);

namespace Kernel\Annotation;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Bind
{
    /**
     * @var string
     */
    public string $class;

    /**
     * Hook constructor.
     * @param string $class
     */
    public function __construct(string $class)
    {
        $this->class = $class;
    }
}