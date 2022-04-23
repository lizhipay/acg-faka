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
     * @param \App\Model\UserGroup|null $group
     * @param string|null $race
     * @return float
     */
    public function calcAmount(int $owner, int $num, Commodity $commodity, ?UserGroup $group, ?string $race = null): float;

    /**
     * @param \App\Model\Commodity $commodity
     * @param \App\Model\UserGroup|null $group
     * @param int $owner
     * @param int $num
     * @param string|null $race
     */
    public function parseConfig(Commodity &$commodity, ?UserGroup $group, int $owner = 0, int $num = 1, ?string $race = null): void;

    /**
     * @param \App\Model\Commodity $commodity
     * @param \App\Model\UserGroup|null $group
     * @return array|null
     */
    public function userDefinedPrice(Commodity $commodity, ?UserGroup $group): ?array;


    /**
     * @param \App\Model\User|null $user
     * @param \App\Model\UserGroup|null $userGroup
     * @param array $map
     * @return array
     */
    public function trade(?User $user, ?UserGroup $userGroup, array $map): array;


    /**
     * @param \App\Model\User|null $user
     * @param \App\Model\UserGroup|null $userGroup
     * @param int $cardId
     * @param int $num
     * @param string $coupon
     * @param int|\App\Model\Commodity|null $commodityId
     * @param string|null $race
     * @param bool $disableShared
     * @return array
     */
    public function getTradeAmount(?User $user, ?UserGroup $userGroup, int $cardId, int $num, string $coupon, int|Commodity|null $commodityId, ?string $race = null, bool $disableShared = false, bool $inShop = true): array;

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