<?php
declare(strict_types=1);

namespace App\Service\Impl;

use App\Model\Category;
use App\Model\Config;
use App\Model\UserGroup;
use App\Service\Shop;
use App\Util\Client;
use Illuminate\Database\Eloquent\Builder;

class ShopService implements Shop
{

    /**
     * @param \App\Model\UserGroup|null $group
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function getCategory(?UserGroup $group): array
    {
        $category = \App\Model\Category::query()->withCount(['children as commodity_count' => function (Builder $builder) {
            $builder->where("status", 1);
        }])->where("status", 1)->orderBy("sort", "asc");

        $bus = \App\Model\Business::get(Client::getDomain());

        if ($bus) {
            //商家
            if ($bus->master_display == 0) {
                $category = $category->where("owner", $bus->user_id);
            } else {
                $category = $category->whereRaw("(`owner`=0 or `owner`={$bus->user_id})");
            }
        } else {
            //主站
            if (Config::get("substation_display") == 1) {
                //显示商家
                $list = (array)json_decode(Config::get("substation_display_list"), true);
                $let = "(`owner`=0 or ";
                foreach ($list as $userId) {
                    $let .= "`owner`={$userId} or ";
                }
                $let = trim(trim($let), "or") . ")";
                $category = $category->whereRaw($let);
            } else {
                $category = $category->where("owner", 0);
            }
        }
        //拿到最终的分类数据
        $category = $category->get();

        foreach ($category as $index => $item) {
            if ($item instanceof Category) {
                $levelConfig = $item->getLevelConfig($group);
                if ($item->hide == 1 && (!$levelConfig || !isset($levelConfig['show']) || (int)$levelConfig['show'] != 1)) {
                    unset($category[$index]);
                }
            }
        }

        $array = $category->toArray();
        $array = array_values($array);

        return $array;
    }
}