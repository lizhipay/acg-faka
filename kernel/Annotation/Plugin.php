<?php
declare(strict_types=1);

namespace Kernel\Annotation;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Plugin
{

    const START = 0x1;
    const STOP = 0x2;
    const UNINSTALL = 0x3;
    const INSTALL = 0x4;
    const UPGRADE = 0x5;
    const SAVE_CONFIG = 0x6;

    /**
     * @var int
     */
    public int $state;

    /**
     * Hook constructor.
     * @param int $state
     */
    public function __construct(int $state)
    {
    }
}