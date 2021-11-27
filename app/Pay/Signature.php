<?php
declare(strict_types=1);

namespace App\Pay;


interface Signature
{
    /**
     * @param array $data
     * @param array $config
     * @return bool
     */
    public function verification(array $data, array $config): bool;
}