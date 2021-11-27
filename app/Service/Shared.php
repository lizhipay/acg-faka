<?php
declare(strict_types=1);

namespace App\Service;


interface Shared
{

    /**
     * 连接店铺
     * @param string $domain
     * @param string $appId
     * @param string $appKey
     * @return array|null
     */
    public function connect(string $domain, string $appId, string $appKey): ?array;


    /**
     * 获取店铺项目
     * @param \App\Model\Shared $shared
     * @return array|null
     */
    public function items(\App\Model\Shared $shared): ?array;


    /**
     * @param \App\Model\Shared $shared
     * @param int $commodityId
     * @param int $cardId
     * @param int $num
     * @return bool
     */
    public function inventoryState(\App\Model\Shared $shared, string $sharedCode, int $cardId, int $num): bool;

    /**
     * @param \App\Model\Shared $shared
     * @param string $sharedCode
     * @return array
     */
    public function inventory(\App\Model\Shared $shared, string $sharedCode): array;


    /**
     * 远程购买卡密
     * @param \App\Model\Shared $shared
     * @param int $commodityId
     * @param string $contact
     * @param int $num
     * @param int $cardId
     * @param int $device
     * @param string $password
     * @return string
     */
    public function trade(\App\Model\Shared $shared, string $sharedCode, string $contact, int $num, int $cardId, int $device, string $password): string;

    /**
     * @param \App\Model\Shared $shared
     * @param string $sharedCode
     * @param int $page
     * @return array
     */
    public function draftCard(\App\Model\Shared $shared, string $sharedCode, int $page): array;
}