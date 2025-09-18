<?php
declare(strict_types=1);

namespace App\Service;


use App\Model\Commodity;
use Kernel\Annotation\Bind;

#[Bind(class: \App\Service\Bind\Shared::class)]
interface Shared
{

    /**
     * 连接店铺
     * @param string $domain
     * @param string $appId
     * @param string $appKey
     * @param int $type
     * @return array|null
     */
    public function connect(string $domain, string $appId, string $appKey, int $type = 0): ?array;


    /**
     * 获取店铺项目
     * @param \App\Model\Shared $shared
     * @return array|null
     */
    public function items(\App\Model\Shared $shared): ?array;

    /**
     * @param \App\Model\Shared $shared
     * @param string $code
     * @return array
     */
    public function item(\App\Model\Shared $shared, string $code): array;


    /**
     * @param \App\Model\Shared $shared
     * @param Commodity $commodity
     * @param int $cardId
     * @param int $num
     * @param string $race
     * @return bool
     */
    public function inventoryState(\App\Model\Shared $shared, Commodity $commodity, int $cardId, int $num, string $race): bool;

    /**
     * @param \App\Model\Shared $shared
     * @param Commodity $commodity
     * @param string $race
     * @return array
     */
    public function inventory(\App\Model\Shared $shared, Commodity $commodity, string $race = ""): array;


    /**
     * 远程购买卡密
     * @param \App\Model\Shared $shared
     * @param Commodity $commodity
     * @param string $contact
     * @param int $num
     * @param int $cardId
     * @param int $device
     * @param string $password
     * @param string $race
     * @param array|null $sku
     * @param string|null $widget
     * @param string $requestNo
     * @return string
     */
    public function trade(\App\Model\Shared $shared, Commodity $commodity, string $contact, int $num, int $cardId, int $device, string $password, string $race, ?array $sku, ?string $widget, string $requestNo): string;

    /**
     * @param \App\Model\Shared $shared
     * @param string $code
     * @param array $map
     * @return array
     */
    public function draftCard(\App\Model\Shared $shared, string $code, array $map = []): array;

    /**
     * @param \App\Model\Shared $shared
     * @param string $code
     * @param int $cardId
     * @return array
     */
    public function getDraft(\App\Model\Shared $shared, string $code, int $cardId): array;


    /**
     * @param \App\Model\Shared $shared
     * @param string $code
     * @param string|null $race
     * @param null|array $sku
     * @return string
     */
    public function getItemStock(\App\Model\Shared $shared, string $code, ?string $race = null, ?array $sku = []): string;


    /**
     * @param string $config
     * @param string $price
     * @param string $userPrice
     * @param int $type
     * @param float $premium
     * @return array
     */
    public function AdjustmentPrice(string $config, string $price, string $userPrice, int $type, float $premium): array;


    /**
     * @param int $type
     * @param float $premium
     * @param string|int|float $amount
     * @return string
     */
    public function AdjustmentAmount(int $type, float $premium, string|int|float $amount): string;
}