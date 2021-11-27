<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Interceptor\Waf;
use App\Model\Config;
use Kernel\Annotation\Interceptor;

#[Interceptor(Waf::class)]
class Site extends User
{
    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function info(): array
    {
        return $this->json(200, "success", [
            "shop_name" => Config::get("shop_name"),
            "title" => Config::get("title"),
            "description" => Config::get("description"),
            "keywords" => Config::get("keywords"),
            "user_theme" => Config::get("user_theme"),
            "registered_state" => Config::get("registered_state"),
            "notice" => Config::get("notice"),
        ]);
    }
}