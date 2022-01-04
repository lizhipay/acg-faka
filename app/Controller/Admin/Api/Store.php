<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\CreateObjectEntity;
use App\Entity\DeleteBatchEntity;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Model\Shared;
use App\Service\Query;
use App\Util\Date;
use App\Util\Str;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Store extends Manage
{

    #[Inject]
    private Query $query;

    #[Inject]
    private \App\Service\Shared $shared;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(Shared::class);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setWhere($map);
        $data = $this->query->findTemplateAll($queryTemplateEntity)->toArray();
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

        if (!$map['domain']) {
            throw new JSONException("店铺地址不能为空");
        }

        if (!$map['app_id']) {
            throw new JSONException("商户ID不能为空");
        }

        if (!$map['app_key']) {
            throw new JSONException("商户密钥不能为空");
        }

        $map['domain'] = trim($map['domain'], "/");

        $connect = $this->shared->connect($map['domain'], $map['app_id'], $map['app_key']);

        $map['name'] = $connect['shopName'];
        $map['balance'] = $connect['balance'];

        $createObjectEntity = new CreateObjectEntity();
        $createObjectEntity->setModel(Shared::class);
        $createObjectEntity->setMap($map);
        $createObjectEntity->setCreateDate("create_time");
        $save = $this->query->createOrUpdateTemplate($createObjectEntity);
        if (!$save) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }
        return $this->json(200, '（＾∀＾）保存成功');
    }

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function connect(): array
    {
        $id = (int)$_POST['id'];
        $shared = Shared::query()->find($id);

        if (!$shared) {
            throw new JSONException("未找到该店铺");
        }
        $connect = $this->shared->connect($shared->domain, $shared->app_id, $shared->app_key);
        $shared->name = (string)$connect['shopName'];
        $shared->balance = (float)$connect['balance'];
        $shared->save();
        return $this->json(200, 'success');
    }

    /**
     * @return array
     * @throws \Kernel\Exception\JSONException
     */
    public function items(): array
    {
        $id = (int)$_POST['id'];
        $shared = Shared::query()->find($id);

        if (!$shared) {
            throw new JSONException("未找到该店铺");
        }
        $items = $this->shared->items($shared);

        foreach ($items as $key => $item) {
            $items[$key]['disabled'] = true;
        }
        return $this->json(200, 'success', $items);
    }

    /**
     * @throws \Kernel\Exception\JSONException
     */
    public function addItem(): array
    {
        $categoryId = (int)$_POST['category_id'];
        $storeId = (int)$_POST['store_id'];
        $items = (array)$_POST['items'];

        $shared = Shared::query()->find($storeId);

        if (!$shared) {
            throw new JSONException("未找到该店铺");
        }

        $date = Date::current();
        $count = count($items);
        $success = 0;
        $error = 0;

        foreach ($items as $item) {
            try {
                $commodity = new \App\Model\Commodity();
                $commodity->category_id = $categoryId;
                $commodity->name = $item['name'];
                $commodity->description = $item['description'];
                $commodity->cover = $shared->domain . $item['cover'];
                $commodity->factory_price = $item['user_price'];
                $commodity->price = $item['price'];
                $commodity->user_price = $item['price'];
                $commodity->status = 0;
                $commodity->owner = 0;
                $commodity->create_time = $date;
                $commodity->api_status = 0;
                $commodity->code = strtoupper(Str::generateRandStr(16));
                $commodity->delivery_way = $item['delivery_way'];
                $commodity->delivery_message = $item['delivery_message'];
                $commodity->contact_type = $item['contact_type'];
                $commodity->password_status = $item['password_status'];
                $commodity->sort = $item['sort'];
                $commodity->lot_status = 0;
                $commodity->coupon = 0;
                $commodity->shared_id = $storeId;
                $commodity->shared_code = $item['code'];
                $commodity->seckill_status = $item['seckill_status'];
                if ($commodity->seckill_status == 1) {
                    $commodity->seckill_start_time = $item['seckill_start_time'];
                    $commodity->seckill_end_time = $item['seckill_end_time'];
                }

                $commodity->draft_status = $item['draft_status'];
                if ($commodity->draft_status) {
                    $commodity->draft_premium = $item['draft_premium'];
                }

                //2022/01/05新增
                $commodity->inventory_hidden = $item['inventory_hidden'];
                $commodity->leave_message = $item['leave_message'];
                $commodity->only_user = $item['only_user'];
                $commodity->purchase_count = $item['purchase_count'];
                $commodity->widget = $item['widget'];
                $commodity->minimum = $item['minimum'];

                $commodity->save();
                $success++;
            } catch (\Exception $e) {
                $error++;
            }
        }

        return $this->json(200, "拉取结束，总数量：{$count}，成功：{$success}，失败：{$error}");
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {
        $deleteBatchEntity = new DeleteBatchEntity();
        $deleteBatchEntity->setModel(Shared::class);
        $deleteBatchEntity->setList($_POST['list']);
        $count = $this->query->deleteTemplate($deleteBatchEntity);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }
        return $this->json(200, '（＾∀＾）移除成功');
    }
}