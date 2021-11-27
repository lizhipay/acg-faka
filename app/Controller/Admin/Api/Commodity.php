<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\CreateObjectEntity;
use App\Entity\DeleteBatchEntity;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Service\Query;
use App\Util\Client;
use App\Util\Str;
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
            $relation->select(["id", "username", "avatar"]);
        }]);
        $data = $this->query->findTemplateAll($queryTemplateEntity)->toArray();

        foreach ($data['data'] as $key => $val) {
            $data['data'][$key]['share_url'] = Client::getUrl() . "?code=" . urlencode(base64_encode("a={$val['category_id']}&b={$val['id']}"));
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

            if ((float)$map['price'] <= 0 || (float)$map['user_price'] <= 0) {
                throw new JSONException("商品单价不能低于0元或者免费哦(｡￫‿￩｡)");
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

        if ($map['lot_status'] == 1) {
            if (!$map['lot_config']) {
                throw new JSONException("您开启了批发优惠，请填写详细的优惠规则哦(｡￫‿￩｡)");
            }
            $lotConfig = explode(PHP_EOL, trim(trim($map['lot_config']), PHP_EOL));
            foreach ($lotConfig as $item) {
                if (count(explode("-", $item)) != 2) {
                    throw new JSONException("批发优惠规则填写有误，请认真填写(｡￫‿￩｡)");
                }
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


        $createObjectEntity = new CreateObjectEntity();
        $createObjectEntity->setModel(\App\Model\Commodity::class);
        $createObjectEntity->setMap($map, ["description", "delivery_message", "lot_config", "shared_code"]);
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
        $deleteBatchEntity = new DeleteBatchEntity();
        $deleteBatchEntity->setModel(\App\Model\Commodity::class);
        $deleteBatchEntity->setList($_POST['list']);
        $count = $this->query->deleteTemplate($deleteBatchEntity);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }
        return $this->json(200, '（＾∀＾）移除成功');
    }
}