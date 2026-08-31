<?php
declare(strict_types=1);

namespace App\Service;

use Kernel\Annotation\Bind;

/**
 * Interface Pay
 * @package App\Service
 */
#[Bind(class: \App\Service\Bind\Pay::class)]
interface Pay
{
    /**
     * @return array
     */
    public function getPlugins(): array;

    /**
     * @param string $name
     * @return array
     */
    public function getPluginInfo(string $name): array;

    /**
     * @param string $handle
     * @return string
     */
    public function getPluginLog(string $handle): string;

    /**
     * @param string $handle
     * @return bool
     */
    public function ClearPluginLog(string $handle): bool;

    /**
     * Persist only configuration fields declared by the selected payment
     * plugin (plus the explicit admin-only `top` flag).
     *
     * @param string $name
     * @param array $config
     * @param int|null $configId 写哪一套配置，null=默认配置档
     * @return void
     */
    public function savePluginConfig(string $name, array $config, ?int $configId = null): void;

    /**
     * 某支付插件的全部配置档（含脱敏后的值与表单定义）
     * @param string $handle
     * @return array
     */
    public function listPluginConfigs(string $handle): array;

    /**
     * 新建一套空配置
     * @param string $handle
     * @param string $name
     * @return int
     */
    public function createPluginConfig(string $handle, string $name): int;

    /**
     * @param string $handle
     * @param int $id
     * @param string $name
     * @return void
     */
    public function renamePluginConfig(string $handle, int $id, string $name): void;

    /**
     * 删除一套配置，被支付接口引用时拒绝
     * @param string $handle
     * @param int $id
     * @return void
     */
    public function deletePluginConfig(string $handle, int $id): void;
}
