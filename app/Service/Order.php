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
     * @param Commodity $commodity
     * @param UserGroup|null $group
     * @param string|null $race
     * @param bool $disableSubstation
     * @return float
     */
    public function calcAmount(int $owner, int $num, Commodity $commodity, ?UserGroup $group, ?string $race = null, bool $disableSubstation = false): float;

    /**
     * @param Commodity $commodity
     * @param UserGroup|null $group
     * @param int $owner
     * @param int $num
     * @param string|null $race
     */
    public function parseConfig(Commodity &$commodity, ?UserGroup $group, int $owner = 0, int $num = 1, ?string $race = null): void;

    /**
     * @param Commodity $commodity
     * @param UserGroup|null $group
     * @return array|null
     */
    public function userDefinedPrice(Commodity $commodity, ?UserGroup $group): ?array;


    /**
     * @param User|null $user
     * @param UserGroup|null $userGroup
     * @param array $map
     * @return array
     */
    public function trade(?User $user, ?UserGroup $userGroup, array $map): array;


    /**
     * @param User|null $user
     * @param UserGroup|null $userGroup
     * @param int $cardId
     * @param int $num
     * @param string $coupon
     * @param int|Commodity|null $commodityId
     * @param string|null $race
     * @param bool $disableShared
     * @return array
     */
    public function getTradeAmount(?User $user, ?UserGroup $userGroup, int $cardId, int $num, string $coupon, int|Commodity|null $commodityId, ?string $race = null, bool $disableShared = false): array;

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

    /**
     * 赠送礼品
     * @param Commodity $commodity
     * @param string $race
     * @param int $num
     * @param string $contact
     * @param string $password
     * @param int|null $cardId
     * @param int $userId
     * @param string $widget
     * @return array
     */
    public function giftOrder(Commodity $commodity, string $race = "", int $num = 1, string $contact = "", string $password = "", ?int $cardId = null, int $userId = 0, string $widget = "[]"): array;
}