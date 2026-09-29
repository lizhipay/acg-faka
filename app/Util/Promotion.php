<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Config;

final class Promotion
{
    public static function enabled(): bool
    {
        return Config::cached("promote_state") !== "0";
    }
}
