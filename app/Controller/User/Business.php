<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Controller\Base\View\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Model\BusinessLevel;
use App\Model\Config;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserSession::class])]
class Business extends User
{
    /**
     * @return string
     * @throws \Kernel\Exception\ViewException|\Kernel\Exception\JSONException
     */
    public function index(): string
    {

        $user = $this->getUser();
        $businessLevel = $user->businessLevel;
        $level = BusinessLevel::query()->orderBy("price", "asc");
        if ($businessLevel) {
            $level = $level->where("price", ">", $businessLevel->price)->where("id", "!=", $businessLevel->id);
        }
        $level = $level->get()->toArray();

        $configDomain = (array)explode(",", Config::get("domain"));
        $business = $user->business;

        if ($business instanceof \App\Model\Business) {
            foreach ($configDomain as $item) {
                $business->subdomain = str_replace("." . $item, "", (string)$business->subdomain);
            }
        }

        return $this->theme("我的店铺", "BUSINESS", "User/Business.html", [
            "me" => $user->toArray(),
            "level" => $level,
            "business" => $business,
            "purchase" => (int)$_GET['purchase'],
            "domain" => $configDomain
        ]);
    }
}