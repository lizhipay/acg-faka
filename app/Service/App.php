<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Interface App
 * @package App\Service
 */
interface App
{
    /**
     * 应用商店地址
     */
    const APP_URL = "https://store.acged.cc";

    /**
     * @return array
     */
    public function getVersions(): array;

    /**
     * 升级
     */
    public function update(): void;


    /**
     * @return array
     */
    public function ad(): array;

    /**
     *
     */
    public function install(): void;
}