<?php
declare(strict_types=1);

namespace App\Service;


use App\Model\Commodity;
use App\Model\User;
use App\Model\UserGroup;

interface Order
{

    /**
     * @param int $owner
     * @param int $num
     * @param \App\Model\Commodity $commodity
     * @return float
     */
    public function calcAmount(int $owner, int $num, Commodity $commodity): float;


    /**
     * @param \App\Model\User|null $user
     * @param \App\Model\UserGroup|null $userGroup
     * @return array
     */
    public function trade(?User $user, ?UserGroup $userGroup): array;


    /**
     * @param \App\Model\User|null $user
     * @param \App\Model\UserGroup|null $userGroup
     * @param int $num
     * @param string $coupon
     * @param int $commodityId
     * @return array
     */
    public function getTradeAmount(?User $user, ?UserGroup $userGroup, int $cardId, int $num, string $coupon, int $commodityId): array;

    /**
     * @param string $handle
     * @param array $map
     * @return string
     */
    public function callback(string $handle, array $map): string;

    /**
     * @param \App\Model\Order $order
     */
    public function orderSuccess(\App\Model\Order $order): string;

    /**
     * @param string $handle
     * @param array $map
     * @return array
     */
    public function callbackInitialize(string $handle, array $map): array;
}