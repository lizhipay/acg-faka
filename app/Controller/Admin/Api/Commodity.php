<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\CreateObjectEntity;
use App\Entity\DeleteBatchEntity;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Service\Query;
use App\Util\Client;
use App\Util\Date;
use App\Util\Ini;
use App\Util\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Commodity extends Manage
{
    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\Commodity::class);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setWhere($map);
        $queryTemplateEntity->setOrder('sort', 'asc');
        $queryTemplateEntity->setWith(['shared', 'category', 'owner' => function (Relation $relation) {
            $relation->with(['business' => function (Relation $relation) {
                $relation->select(['id', 'user_id', 'subdomain', 'topdomain']);
            }])->select(["id", "username", "avatar"]);
        }]);
        $queryTemplateEntity->setWithCount(['card as card_count' => function (Builder $builder) {
            $builder->where("status", 0);
        }]);
        $queryTemplateEntity->setWithCount(['card as card_success_count' => function (Builder $builder) {
            $builder->where("status", 1);
        }]);

        //商品总盈利
        $queryTemplateEntity->setWithCount(['order as order_all_amount' => function (Builder $relation) {
            $relation->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_all_amount"));
        }]);
        //过去7天内盈利
        $queryTemplateEntity->setWithCount(['order as order_week_amount' => function (Builder $relation) {
            $relation->whereBetween('create_time', [Date::weekDay(1, Date::TYPE_START), Date::weekDay(7, Date::TYPE_END)])->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_week_amount"));
        }]);
        //昨日盈利
        $queryTemplateEntity->setWithCount(['order as order_yesterday_amount' => function (Builder $relation) {
            $relation->whereBetween('create_time', [Date::calcDay(-1), Date::calcDay()])->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_yesterday_amount"));
        }]);
        //今日盈利
        $queryTemplateEntity->setWithCount(['order as order_today_amount' => function (Builder $relation) {
            $relation->whereBetween('create_time', [Date::calcDay(), Date::calcDay(1)])->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_today_amount"));
        }]);

        $data = $this->query->findTemplateAll($queryTemplateEntity)->toArray();

        $clientUrl = Client::getUrl();
        foreach ($data['data'] as $key => $val) {
            $url = $clientUrl;
            if ($val['owner'] && $val['owner']['business']) {

                if ($val['owner']['business']['subdomain']) {
                    $url = "http://" . $val['owner']['business']['subdomain'];
                }

                if ($val['owner']['business']['topdomain']) {
                    $url = "http://" . $val['owner']['business']['topdomain'];
                }
            }
            $data['data'][$key]['share_url'] = $url . "?cid={$val['category_id']}&mid={$val['id']}";
        }

        $json = $this->json(200, null, $data['data']);
        $json['count'] = $data['total'];
        return $json;
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function save(): array
    {
        $map = $_POST;


        //create new
        if ((int)$map['id'] == 0) {

            if (!$map['name']) {
                throw new JSONException("商品名称不能为空哦(｡￫‿￩｡)");
            }

            if ((float)$map['price'] < 0 || (float)$map['user_price'] < 0) {
                throw new JSONException("商品单价不能低于0元哦(｡￫‿￩｡)");
            }

            //--init
            $map['owner'] = 0;
            $map['code'] = strtoupper(Str::generateRandStr(16));
        }

        //如果选择了别人平台
        if ((int)$map['shared_id'] != 0) {
            $map['delivery_way'] = 0;
            if (!$map['shared_code']) {
                throw new JSONException("您选择了对接别人店铺，所以要填写商品对接代码哦(｡￫‿￩｡)");
            }
        }

        if ($map['seckill_status'] == 1) {
            if (!$map['seckill_start_time'] || !$map['seckill_end_time']) {
                throw new JSONException("您开启了秒杀功能，所以请指定秒杀的开始时间和结束时间哦(｡￫‿￩｡)");
            }
            if (strtotime($map['seckill_end_time']) < strtotime($map['seckill_start_time'])) {
                throw new JSONException("秒杀结束时间不能低于秒杀开始时间哦，请认真指定秒杀结束时间(｡￫‿￩｡)");
            }
        }

        if ($map['draft_status'] == 1) {
            if ($map['draft_premium'] === "") {
                throw new JSONException("您开启了预选卡密功能，请填写预选时的溢价(｡￫‿￩｡)");
            }
        }

        //解析配置文件
        if ($map['config']) {
            Ini::toArray($map['config']);
        }

        $createObjectEntity = new CreateObjectEntity();
        $createObjectEntity->setModel(\App\Model\Commodity::class);
        $createObjectEntity->setMap($map, ["description", "delivery_message", "shared_code", "config"]);
        $createObjectEntity->setCreateDate("create_time");
        $save = $this->query->createOrUpdateTemplate($createObjectEntity);
        if (!$save) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }

        ManageLog::log($this->getManage(), "[修改/新增]商品");
        return $this->json(200, '（＾∀＾）保存成功');
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {
        $deleteBatchEntity = new DeleteBatchEntity();
        $deleteBatchEntity->setModel(\App\Model\Commodity::class);
        $deleteBatchEntity->setList($_POST['list']);
        $count = $this->query->deleteTemplate($deleteBatchEntity);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }

        ManageLog::log($this->getManage(), "[删除]商品");
        return $this->json(200, '（＾∀＾）移除成功');
    }

    /**
     * @return array
     */
    public function status(): array
    {
        $list = (array)$_POST['list'];
        $status = (int)$_POST['status'];
        \App\Model\Commodity::query()->whereIn('id', $list)->update(['status' => $status]);
        ManageLog::log($this->getManage(), "[批量更新]商品启停状态");
        return $this->json(200, '商品状态已经更新');
    }


    /**
     * @return array
     */
    public function fastEnable(): array
    {
        $list = (array)explode(",", (string)$_POST['list']);
        unset($_POST['list']);
        foreach ($_POST as $key => $val) {
            if ($val == 0) {
                $_POST[$key] = 0;
            } else {
                $_POST[$key] = 1;
            }
        }
        $update = \App\Model\Commodity::query()->whereIn('id', $list)->update($_POST);

        ManageLog::log($this->getManage(), "[批量更新]商品状态");
        return $this->json(200, '更新成功');
    }
}