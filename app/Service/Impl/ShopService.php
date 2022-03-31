<?php
declare(strict_types=1);

namespace App\Service\Impl;

use App\Model\Category;
use App\Model\Commodity;
use App\Model\Config;
use App\Model\UserCategory;
use App\Model\UserGroup;
use App\Service\Shop;
use App\Util\Client;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Exception\JSONException;

class ShopService implements Shop
{

    /**
     * @param UserGroup|null $group
     * @return array
     * @throws JSONException
     */
    public function getCategory(?UserGroup $group): array
    {
        $category = \App\Model\Category::query()->withCount(['children as commodity_count' => function (Builder $builder) {
            $builder->where("status", 1);
        }])->where("status", 1)->orderBy("sort", "asc");

        $bus = \App\Model\Business::get(Client::getDomain());
        $userCategoryMap = []; //自定义名称的MAP
        $master = true;

        if ($bus) {
            $master = false;
            //商家
            if ($bus->master_display == 0) {
                $category = $category->where("owner", $bus->user_id);
            } else {
                //查询出所有不显示的ID
                $userCategory = UserCategory::query()->where("user_id", $bus->user_id)->get();
                //隐藏的分类ID
                $hideCategory = [];

                foreach ($userCategory as $userCate) {
                    if ($userCate->status == 0) {
                        $hideCategory[] = $userCate->category_id;
                    } else {
                        $userCategoryMap[$userCate->category_id] = $userCate;
                    }
                }

                $category = $category->whereNotIn("id", $hideCategory)->whereRaw("(`owner`=0 or `owner`={$bus->user_id})");
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

            $levelConfig = $item->getLevelConfig($group);
            if ($item->hide == 1 && (!$levelConfig || !isset($levelConfig['show']) || (int)$levelConfig['show'] != 1)) {
                unset($category[$index]);
                continue;
            }

            if (isset($userCategoryMap[$item->id])) {
                $var = $userCategoryMap[$item->id];
                if ($var->name) {
                    $category[$index]['name'] = $var->name;
                }
            }

            if (!$item->icon) {
                $category[$index]['icon'] = '/favicon.ico';
            }
        }

        $array = $category->toArray();
        $array = array_values($array);

        $commodityRecommend = Config::get("commodity_recommend");
        if ($commodityRecommend == 1 && $master) {
            array_unshift($array, [
                "id" => -10,
                "name" => "<b style='color: #ef783b;'>" . Config::get("commodity_name") . "</b>",
                "sort" => 1,
                "create_time" => "-",
                "owner" => 0,
                "icon" => "/assets/static/images/recommend.png",
                "status" => 1,
                "hide" => 0,
                "user_level_config" => null,
                "commodity_count" => Commodity::query()->where("status", 1)->where("recommend", 1)->count(),
            ]);
        }

        return $array;
    }
}