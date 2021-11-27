<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Interface Cash
 * @package App\Service
 */
interface Cash
{
    /**
     * @param float $amount
     */
    public function settlement(float $amount): void;
}