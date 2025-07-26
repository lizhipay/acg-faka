<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Impl\PayService;
use Kernel\Annotation\Bind;

/**
 * Interface Pay
 * @package App\Service
 */
#[Bind(class: PayService::class)]
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
}