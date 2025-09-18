<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Model\Shared;
use App\Service\Image;
use App\Service\Query;
use App\Util\Date;
use App\Util\Ini;
use App\Util\Str;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Store extends Manage
{

    #[Inject]
    private Query $query;

    #[Inject]
    private \App\Service\Shared $shared;

    #[Inject]
    private Image $image;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $get = new Get(Shared::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $data = $this->query->get($get);
        return $this->json(data: $data);
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

        $connect = $this->shared->connect($map['domain'], $map['app_id'], $map['app_key'], (int)$map['type']);

        $map['name'] = strip_tags((string)$connect['shopName']);
        $map['balance'] = (float)$connect['balance'];

        $save = new Save(Shared::class);
        $save->setMap($map);
        $save->enableCreateTime();
        $save = $this->query->save($save);
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
        $connect = $this->shared->connect($shared->domain, $shared->app_id, $shared->app_key, $shared->type);
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
    public function addItem(Request $request): array
    {
        $map = $request->post(flags: Filter::NORMAL);

        $categoryId = (int)$map['category_id'];
        $storeId = (int)$_GET['storeId'];
        $items = (array)$map['items'];
        $premium = (float)$map['premium']; // 加价金额
        $premiumType = (int)$map['premium_type']; // 加价模式
        $imageDownload = (bool)$map['image_download'];
        $shelves = (int)$map['shelves'] == 0 ? 0 : 1; // 立即上架

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
                preg_match_all('#<img.*?src="(/.*?)"#', $commodity->description, $matchs);
                $list = (array)$matchs[1];

                if (count($list) > 0) {
                    foreach ($list as $e) {
                        //远端图片下载
                        if ($imageDownload) {
                            $download = $this->image->downloadRemoteImage($shared->domain . $e);
                            $commodity->description = str_replace($e, $download[0], $commodity->description);
                        } else {
                            $commodity->description = str_replace($e, $shared->domain . $e, $commodity->description);
                        }
                    }
                }

                //远端cover下载
                if ($imageDownload) {
                    $download = $this->image->downloadRemoteImage($shared->domain . $item['cover']);
                    $commodity->cover = $download[0];
                } else {
                    $commodity->cover = $shared->domain . $item['cover'];
                }

                $commodity->status = $shelves;
                $commodity->owner = 0;
                $commodity->create_time = $date;
                $commodity->api_status = 0;
                $commodity->code = strtoupper(Str::generateRandStr(16));
                $commodity->delivery_way = 1;
                $commodity->contact_type = $item['contact_type'];
                $commodity->password_status = $item['password_status'];
                $commodity->sort = 0;
                $commodity->coupon = 0;
                $commodity->shared_id = $storeId;
                $commodity->shared_code = $item['code'];
                $commodity->shared_premium = $premium;
                $commodity->shared_premium_type = $premiumType;
                $commodity->seckill_status = $item['seckill_status'];

                if ($commodity->seckill_status == 1) {
                    $commodity->seckill_start_time = $item['seckill_start_time'];
                    $commodity->seckill_end_time = $item['seckill_end_time'];
                }

                $commodity->draft_status = $item['draft_status'];

                if ($commodity->draft_status) {
                    $commodity->draft_premium = $this->shared->AdjustmentAmount($premiumType, $premium, $item['draft_premium']);
                }

                //2022/01/05新增
                $commodity->inventory_hidden = $item['inventory_hidden'];
                $commodity->only_user = $item['only_user'];
                $commodity->purchase_count = $item['purchase_count'];
                $commodity->widget = $item['widget'];
                $commodity->minimum = $item['minimum'];
                $commodity->stock = $item['stock'];

                //自动加价
                $config = $this->shared->AdjustmentPrice((string)$item['config'], $item['price'], $item['user_price'], $premiumType, $premium);

                $commodity->config = Ini::toConfig($config['config']);
                $commodity->price = $config['price'];
                $commodity->user_price = $config['user_price'];

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
        $deleteBatchEntity = new Delete(Shared::class, $_POST['list']);
        $count = $this->query->delete($deleteBatchEntity);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }

        ManageLog::log($this->getManage(), "[店铺共享]删除操作，共计：" . count($_POST['list']));
        return $this->json(200, '（＾∀＾）移除成功');
    }
}