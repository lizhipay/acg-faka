<?php
declare(strict_types=1);

namespace App\Service;


use App\Model\Commodity;
use App\Model\User;
use App\Model\UserGroup;
use Kernel\Annotation\Bind;

#[Bind(class: \App\Service\Bind\Order::class)]
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
     * @param Commodity|int $commodity
     * @param int $num
     * @param string|null $race
     * @param array|null $sku
     * @param int|null $cardId
     * @param string|null $coupon
     * @param UserGroup|null $group
     * @return string
     */
    public function valuation(Commodity|int $commodity, int $num = 1, ?string $race = null, ?array $sku = [], ?int $cardId = null, ?string $coupon = null, ?UserGroup $group = null): string;

    /**
     * @param int $commodityId
     * @param string|float|int $price
     * @param UserGroup|null $group
     * @return string
     */
    public function getValuationPrice(int $commodityId, string|float|int $price, ?UserGroup $group = null): string;

    /**
     * @param Commodity $commodity
     * @param UserGroup|null $group
     */
    public function parseConfig(Commodity &$commodity, ?UserGroup $group): void;

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
     * @param array|null $sku
     * @param bool $disableShared
     * @return array
     */
    public function getTradeAmount(?User $user, ?UserGroup $userGroup, int $cardId, int $num, string $coupon, int|Commodity|null $commodityId, ?string $race = null, ?array $sku = [], bool $disableShared = false): array;


    /**
     * @param string $handle
     * @param array $map
     * @return string
     */
    public function callback(string $handle, array $map): string;


    /**
     * @param \App\Model\Order $order
     * @return string
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