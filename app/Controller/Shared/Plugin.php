<?php
declare(strict_types=1);

namespace App\Controller\Shared;


use App\Controller\Base\API\Shared;
use App\Interceptor\Waf;
use App\Util\Aes;
use Kernel\Annotation\Interceptor;
use Kernel\Util\Context;

#[Interceptor(Waf::class)]
class Plugin extends Shared
{

    /**
     * @return string
     */
    public function face(): string
    {
        $store = config('store');
        $face = ['face' => Context::get(\Kernel\Consts\Base::LOCK)];
        return Aes::encrypt($face, $store['app_key'], $store['app_key']);
    }
}