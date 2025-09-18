<?php
declare(strict_types=1);

namespace App\Service;

use Kernel\Annotation\Bind;

/**
 * Interface Cash
 * @package App\Service
 */
#[Bind(class: \App\Service\Bind\Cash::class)]
interface Cash
{
    /**
     * @param float $amount
     */
    public function settlement(float $amount): void;
}