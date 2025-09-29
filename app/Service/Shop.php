<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Commodity;
use App\Model\User;
use App\Model\UserGroup;
use Kernel\Annotation\Bind;

#[Bind(class: \App\Service\Bind\Shop::class)]
interface Shop
{

    /**
     * @param UserGroup|null $group
     * @return array
     */
    public function getCategory(?UserGroup $group): array;

    /**
     * @param int|string $commodityId
     * @param User|null $user
     * @param UserGroup|null $group
     * @return array
     */
    public function getItem(int|string $commodityId, ?User $user = null, ?UserGroup $group = null): array;

    /**
     * @param int|Commodity $commodity
     * @param string|null $race
     * @param array|null $sku
     * @return string|null
     */
    public function getSharedStock(int|Commodity $commodity, ?string $race = null, ?array $sku = []): string|null;

    /**
     * @param int|Commodity $commodity
     * @param string|null $race
     * @param array|null $sku
     * @return void
     */
    public function updateSharedStock(int|Commodity $commodity, ?string $race = null, ?array $sku = []): void;

    /**
     * @param int $id
     * @param string|null $race
     * @param array|null $sku
     * @return string
     */
    public function getSharedStockHash(int $id, ?string $race = null, ?array $sku = []): string;

    /**
     * @param int|Commodity|string $commodity
     * @param string|null $race
     * @param array|null $sku
     * @return string
     */
    public function getItemStock(int|Commodity|string $commodity, ?string $race = null, ?array $sku = []): string;

    /**
     * @param int|string $stock
     * @return string
     */
    public function getHideStock(int|string $stock): string;

    /**
     * @param int|string $stock
     * @return int
     */
    public function getStockState(int|string $stock): int;

    /**
     * @param int|Commodity|string $commodity
     * @param int $cardId
     * @return array
     */
    public function getDraft(int|Commodity|string $commodity, int $cardId): array;


    /**
     * @param Commodity $commodity
     * @return void
     */
    public function substationPriceIncrease(Commodity &$commodity): void;


    /**
     * @param Commodity|int $commodity
     * @param int|string|float $amount
     * @return string
     */
    public function getSubstationPrice(Commodity|int $commodity, int|string|float $amount): string;
}