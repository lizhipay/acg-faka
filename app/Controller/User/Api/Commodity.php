<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\Business;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Query;
use App\Util\Client;
use App\Util\Date;
use App\Util\Ini;
use App\Util\Str;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor([Waf::class, UserSession::class, Business::class], Interceptor::TYPE_API)]
class Commodity extends User
{
    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $map['equal-owner'] = $this->getUser()->id;
        $get = new Get(\App\Model\Commodity::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setOrderBy(...$this->query->getOrderBy($map, "sort", "asc"));
        $get->setWhere($map);

        $data = $this->query->get($get, function (Builder $builder) {
            return $builder
                ->where("owner", $this->getUser()->id)
                ->with(['category'])
                ->withCount([
                    'card as card_count' => function (Builder $builder) {
                        $builder->where("status", 0);
                    },
                    'card as card_success_count' => function (Builder $builder) {
                        $builder->where("status", 1);
                    },
                    //商品总盈利
                    'order as order_all_amount' => function (Builder $relation) {
                        $relation->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_all_amount"));
                    },
                    //过去7天内盈利
                    'order as order_week_amount' => function (Builder $relation) {
                        $relation->whereBetween('create_time', [Date::weekDay(1, Date::TYPE_START), Date::weekDay(7, Date::TYPE_END)])->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_week_amount"));
                    },
                    //昨日盈利
                    'order as order_yesterday_amount' => function (Builder $relation) {
                        $relation->whereBetween('create_time', [Date::calcDay(-1), Date::calcDay()])->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_yesterday_amount"));
                    },
                    //今日盈利
                    'order as order_today_amount' => function (Builder $relation) {
                        $relation->whereBetween('create_time', [Date::calcDay(), Date::calcDay(1)])->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_today_amount"));
                    }
                ]);
        });

        foreach ($data['list'] as &$item) {
            $item['share_url'] = Client::getUrl() . "/item/{$item['id']}";
        }

        return $this->json(data: $data);
    }


    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function save(Request $request): array
    {
        $map = $request->post(flags: Filter::NORMAL);
        $user = $this->getUser();


        if (isset($map['category_id']) && !\App\Model\Category::query()->where("owner", $user->id)->where("id", $map['category_id'])->exists()) {
            throw new JSONException("分类不存在");
        }

        //--验证自身
        if (!empty($map['id']) && !\App\Model\Commodity::query()->where("id", $map['id'])->where("owner", $user->id)->exists()) {
            throw new JSONException("该商品不存在");
        }

        if (isset($map['name']) && empty($map['name'])) {
            throw new JSONException("商品名称不能为空哦(｡￫‿￩｡)");
        }

        if (isset($map['price']) && ($map['price'] < 0 || $map['user_price'] < 0)) {
            throw new JSONException("商品单价不能低于0元哦(｡￫‿￩｡)");
        }

        //create new
        if (!isset($map['id'])) {
            $map['code'] = strtoupper(Str::generateRandStr(16));
        }


        if (isset($map['seckill_status']) && $map['seckill_status'] == 1) {
            if (!$map['seckill_start_time'] || !$map['seckill_end_time']) {
                throw new JSONException("您开启了秒杀功能，所以请指定秒杀的开始时间和结束时间哦(｡￫‿￩｡)");
            }
            if (strtotime($map['seckill_end_time']) < strtotime($map['seckill_start_time'])) {
                throw new JSONException("秒杀结束时间不能低于秒杀开始时间哦，请认真指定秒杀结束时间(｡￫‿￩｡)");
            }
        }

        if (isset($map['draft_status']) && $map['draft_status'] == 1) {
            if ($map['draft_premium'] === "") {
                throw new JSONException("您开启了预选卡密功能，请填写预选时的溢价(｡￫‿￩｡)");
            }
        }

        if (isset($map['sort'])) {
            if ($map['sort'] < 1000) {
                throw new JSONException("排序最低设置1000");
            }

            if ($map['sort'] > 60000) {
                throw new JSONException("排序最高设置60000");
            }
        }

        //解析配置文件
        if ($map['config']) {
            Ini::toArray($map['config']);
        }

        $save = new Save(\App\Model\Commodity::class);
        $save->setMap($map);
        $save->addForceMap("owner", $user->id);
        $save->enableCreateTime();
        $save = $this->query->save($save);
        if (!$save) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }
        return $this->json(200, '（＾∀＾）保存成功');
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {

        $id = (int)$_POST['id'];

        if ($id == 0) {
            throw new JSONException("请选择删除的商品");
        }

        $commodity = \App\Model\Commodity::query()->where("owner", $this->getUser()->id)->find($id);

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        $commodity->delete();

        return $this->json(200, '（＾∀＾）移除成功');
    }
}