<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\CreateObjectEntity;
use App\Entity\DeleteBatchEntity;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Model\Shared;
use App\Service\Query;
use App\Util\Date;
use App\Util\Str;
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

        $map['name'] = strip_tags((string)$connect['shopName']);
        $map['balance'] = (float)$connect['balance'];

        $createObjectEntity = new CreateObjectEntity();
        $createObjectEntity->setModel(Shared::class);
        $createObjectEntity->setMap($map);
        $createObjectEntity->setCreateDate("create_time");
        $save = $this->query->createOrUpdateTemplate($createObjectEntity);
        if (!$save) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }

        ManageLog::log($this->getManage(), "[修改/新增]共享店铺");
        return $this->json(200, '（＾∀＾）保存成功');
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function connect(): array
    {
        $id = (int)$_POST['id'];
        $shared = Shared::query()->find($id);

        if (!$shared) {
            throw new JSONException("未找到该店铺");
        }
        $connect = $this->shared->connect($shared->domain, $shared->app_id, $shared->app_key);
        $shared->name = strip_tags((string)$connect['shopName']);
        $shared->balance = (float)$connect['balance'];
        $shared->save();
        return $this->json(200, 'success');
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function items(): array
    {
        $id = (int)$_POST['id'];
        $shared = Shared::query()->find($id);

        if (!$shared) {
            throw new JSONException("未找到该店铺");
        }
        $items = $this->shared->items($shared);

        $gan = function (&$items) use (&$gan) {
            foreach ($items as $key => $val) {
                $items[$key]["name"] = strip_tags((string)$val['name']);
                if (isset($val['children']) && !empty($val['children'])) {
                    $gan($items[$key]["children"]);
                }
            }
        };

        $gan($items);

        foreach ($items as $key => $item) {
            $items[$key]['id'] = 0;
        }
        return $this->json(200, 'success', $items);
    }

    /**
     * @throws JSONException
     */
    public function addItem(): array
    {
        $categoryId = (int)$_POST['category_id'];
        $storeId = (int)$_GET['storeId'];
        $items = (array)$_POST['items'];
        $premium = (float)$_POST['premium']; // 加价金额
        $premiumType = (int)$_POST['premium_type']; // 加价模式
        $sharedSync = (int)$_POST['shared_sync'] == 0 ? 0 : 1; // 主从同步
        $inventorySync = (int)$_POST['inventory_sync'] == 0 ? 0 : 1; // 数量同步
        $shelves = (int)$_POST['shelves'] == 0 ? 0 : 1; // 立即上架

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
                //正则处理
                preg_match_all('#<img src="(/.*?)"#', $commodity->description, $matchs);

                $list = (array)$matchs[1];
                if (count($list) > 0) {
                    foreach ($list as $e) {
                        $commodity->description = str_replace($e, $shared->domain . $e, $commodity->description);
                    }
                }

                if ($premiumType == 0) {
                    //普通加价
                    $commodity->price = $item['price'] + $premium;
                    $commodity->user_price = $item['price'] + $premium;
                } else {
                    //百分比加价
                    $commodity->price = $item['price'] + ($premium * $item['price']);
                    $commodity->user_price = $item['price'] + ($premium * $item['price']);
                }

                $commodity->cover = $shared->domain . $item['cover'];
                $commodity->factory_price = $item['factory_price'];
                $commodity->status = $shelves;
                $commodity->owner = 0;
                $commodity->create_time = $date;
                $commodity->api_status = 0;
                $commodity->code = strtoupper(Str::generateRandStr(16));
                $commodity->delivery_way = $item['delivery_way'];
                $commodity->contact_type = $item['contact_type'];
                $commodity->password_status = $item['password_status'];
                $commodity->sort = $item['sort'];
                $commodity->coupon = 0;
                $commodity->shared_id = $storeId;
                $commodity->shared_code = $item['code'];
                $commodity->shared_premium = $premium;
                $commodity->shared_premium_type = $premiumType;
                $commodity->shared_sync = $sharedSync;
                $commodity->inventory_sync = $inventorySync;
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
                $commodity->only_user = $item['only_user'];
                $commodity->purchase_count = $item['purchase_count'];
                $commodity->widget = $item['widget'];
                $commodity->minimum = $item['minimum'];
                $commodity->config = \App\Model\Commodity::premiumConfig((string)$item['config'], $premiumType, $premium);

                $commodity->save();
                $success++;
            } catch (\Exception $e) {
                $error++;
            }
        }

        ManageLog::log($this->getManage(), "[店铺共享]进行了克隆商品({$shared->name})，总数量：{$count}，成功：{$success}，失败：{$error}");
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

        ManageLog::log($this->getManage(), "[店铺共享]删除操作，共计：" . count($_POST['list']));
        return $this->json(200, '（＾∀＾）移除成功');
    }
}