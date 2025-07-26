<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Impl\CashService;
use Kernel\Annotation\Bind;

/**
 * Interface Cash
 * @package App\Service
 */
#[Bind(class: CashService::class)]
interface Cash
{
    /**
     * @param float $amount
     */
    public function settlement(float $amount): void;
}