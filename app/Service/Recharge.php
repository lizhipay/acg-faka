<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\UserRecharge;
use App\Service\Impl\RechargeService;
use Kernel\Annotation\Bind;

/**
 * Interface Recharge
 * @package App\Service
 */
#[Bind(class: RechargeService::class)]
interface Recharge
{

    /**
     * @param \App\Model\User $user
     * @return array
     */
    public function trade(\App\Model\User $user): array;

    /**
     * @param string $handle
     * @param array $map
     * @return string
     */
    public function callback(string $handle, array $map): string;


    /**
     * @param \App\Model\UserRecharge $recharge
     */
    public function orderSuccess(UserRecharge $recharge): void;

    /**
     * @param float $amount
     * @return float
     */
    public function calcAmount(float $amount): float;
}