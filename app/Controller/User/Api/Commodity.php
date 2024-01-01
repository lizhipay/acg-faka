<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Entity\CreateObjectEntity;
use App\Entity\DeleteBatchEntity;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\Business;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Query;
use App\Util\Client;
use App\Util\Ini;
use App\Util\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

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
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\Commodity::class);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setWhere($map);
        $queryTemplateEntity->setOrder('sort', 'asc');
        $queryTemplateEntity->setWith(['category']);
        $queryTemplateEntity->setWithCount(['card as card_count' => function (Builder $builder) {
            $builder->where("status", 0);
        }]);
        $queryTemplateEntity->setWithCount(['card as card_success_count' => function (Builder $builder) {
            $builder->where("status", 1);
        }]);


        $data = $this->query->findTemplateAll($queryTemplateEntity)->toArray();

        foreach ($data['data'] as $key => $val) {
            $data['data'][$key]['share_url'] = Client::getUrl() .  "?cid={$val['category_id']}&mid={$val['id']}";
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
        $user = $this->getUser();
        $map['owner'] = $user->id;

        $category = \App\Model\Category::query()->where("owner", $user->id)->find((int)$map['category_id']);

        if (!$category) {
            throw new JSONException("分类不存在");
        }

        //--验证自身
        if ((int)$map['id'] != 0) {
            $commodity = \App\Model\Commodity::query()->find($map['id']);
            if (!$commodity || $commodity->owner != $user->id) {
                throw new JSONException("该商品不存在");
            }
        }

        if (!$map['name']) {
            throw new JSONException("商品名称不能为空哦(｡￫‿￩｡)");
        }

        if ((float)$map['price'] < 0 || (float)$map['user_price'] < 0) {
            throw new JSONException("商品单价不能低于0元哦(｡￫‿￩｡)");
        }

        //create new
        if ((int)$map['id'] == 0) {
            $map['code'] = strtoupper(Str::generateRandStr(16));
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

        if ((int)$map['sort'] < 1000) {
            throw new JSONException("排序最低设置1000");
        }

        if ((int)$map['sort'] > 60000) {
            throw new JSONException("排序最高设置60000");
        }

        //解析配置文件
        if ($map['config']) {
            Ini::toArray($map['config']);
        }

        $createObjectEntity = new CreateObjectEntity();
        $createObjectEntity->setModel(\App\Model\Commodity::class);
        $createObjectEntity->setMap($map, ["description", "delivery_message", "config"]);
        $createObjectEntity->setCreateDate("create_time");
        $save = $this->query->createOrUpdateTemplate($createObjectEntity);
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
            throw new JSONException("请选择删除的分类");
        }

        $commodity = \App\Model\Commodity::query()->find($id);

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        if ($commodity->owner != $this->getUser()->id) {
            throw new JSONException("该商品不属于你");
        }

        $commodity->delete();

        return $this->json(200, '（＾∀＾）移除成功');
    }
}