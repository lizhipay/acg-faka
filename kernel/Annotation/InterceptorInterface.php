<?php
declare (strict_types=1);

namespace Kernel\Annotation;

/**
 * Interface InterceptorInterface
 * @package Kernel\Annotation
 */
interface InterceptorInterface
{
    /**
     * @param int $type
     * @return void
     */
    public function handle(int $type): void;
}