<?php
declare(strict_types=1);

namespace App\Service;


use App\Model\Commodity;

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
    public function inventory(\App\Model\Shared $shared,  Commodity $commodity, string $race = ""): array;


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
     * @param string|null $widget
     * @param string $requestNo
     * @return string
     */
    public function trade(\App\Model\Shared $shared, Commodity $commodity, string $contact, int $num, int $cardId, int $device, string $password, string $race, ?string $widget, string $requestNo): string;

    /**
     * @param \App\Model\Shared $shared
     * @param string $sharedCode
     * @param int $limit
     * @param int $page
     * @param string $race
     * @return array
     */
    public function draftCard(\App\Model\Shared $shared, string $sharedCode, int $limit, int $page, string $race): array;
}