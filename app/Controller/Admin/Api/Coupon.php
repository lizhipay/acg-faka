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
use App\Util\Date;
use App\Util\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Coupon extends Manage
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
        $queryTemplateEntity->setModel(\App\Model\Coupon::class);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setWhere($map);
        $queryTemplateEntity->setWith([
            'owner' => function (Relation $relation) {
                $relation->select(["id", "username", "avatar"]);
            },
            'commodity' => function (Relation $relation) {
                $relation->select(["id", "name"]);
            },
            'category' => function (Relation $relation) {
                $relation->select(["id", "name"]);
            }
        ]);
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
        $prefix = $_POST['prefix']; //卡密前缀
        $note = $_POST['note']; //备注信息
        $commodityId = (int)$_POST['commodity_id']; //商品ID
        $categoryId = (int)$_POST['category_id']; //分类ID
        $expireTime = (string)$_POST['expire_time'];//到期时间
        $money = (float)$_POST['money']; //金额
        $num = (int)$_POST['num']; //生成数量
        $life = (int)$_POST['life']; //可用次数
        $mode = (int)$_POST['mode']; //抵扣模式
        $race = $_POST['race'];

        if ($money <= 0) {
            throw new JSONException("ಠ_ಠ请输入优惠卷价格");
        }

        if ($expireTime != '' && strtotime($expireTime) < time()) {
            throw new JSONException("ಠ_ಠ优惠卷的过期时间不能是回忆");
        }

        if ($num <= 0) {
            throw new JSONException("ಠ_ಠ最少也要生成1张优惠卷");
        }
        $date = Date::current();
        $success = 0;
        $error = 0;
        $codes = "";

        for ($i = 0; $i < $num; $i++) {
            $voucher = new \App\Model\Coupon();
            $voucher->code = $prefix . strtoupper(Str::generateRandStr(16));
            $voucher->commodity_id = $commodityId;
            $voucher->category_id = $categoryId;
            $voucher->owner = 0;
            $voucher->create_time = $date;
            if ($expireTime != '') {
                $voucher->expire_time = $expireTime;
            }
            $voucher->money = $money;
            $voucher->status = 0;
            $voucher->note = $note;
            $voucher->life = $life;
            $voucher->mode = $mode;
            if ($race) {
                $voucher->race = $race;
            }
            try {
                $voucher->save();
                $success++;
                $codes .= $voucher->code . PHP_EOL;
            } catch (\Exception $e) {
                $error++;
            }
        }

        ManageLog::log($this->getManage(), "[生成优惠卷]成功:{$success}张，失败：{$error}张");
        return $this->json(200, "生成完毕，成功:{$success}张，失败：{$error}张", ["code" => $codes, "success" => $success, "error" => $error]);
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function edit(): array
    {
        $map = $_POST;
        $createObjectEntity = new CreateObjectEntity();
        $createObjectEntity->setModel(\App\Model\Coupon::class);
        $createObjectEntity->setMap($map);
        $save = $this->query->createOrUpdateTemplate($createObjectEntity);
        if (!$save) {
            throw new JSONException("保存失败");
        }

        ManageLog::log($this->getManage(), "[修改优惠卷]编辑了优惠卷信息");
        return $this->json(200, '（＾∀＾）保存成功');
    }


    /**
     * @return array
     */
    public function lock(): array
    {
        $list = (array)$_POST['list'];
        \App\Model\Coupon::query()->whereIn('id', $list)->whereRaw("status!=1")->update(['status' => 2]);

        ManageLog::log($this->getManage(), "[锁定优惠卷]批量锁定了优惠卷，共计：" . count($list));
        return $this->json(200, '锁定成功');
    }

    /**
     * @return array
     */
    public function unlock(): array
    {
        $list = (array)$_POST['list'];
        \App\Model\Coupon::query()->whereIn('id', $list)->whereRaw("status!=1")->update(['status' => 0]);

        ManageLog::log($this->getManage(), "[解锁优惠卷]批量解锁了优惠卷，共计：" . count($list));
        return $this->json(200, '解锁成功');
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {
        $deleteBatchEntity = new DeleteBatchEntity();
        $deleteBatchEntity->setModel(\App\Model\Coupon::class);
        $deleteBatchEntity->setList($_POST['list']);
        $count = $this->query->deleteTemplate($deleteBatchEntity);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }

        ManageLog::log($this->getManage(), "[批量删除]批量删除了优惠卷，共计：" . count($_POST['list']));
        return $this->json(200, '（＾∀＾）移除成功');
    }


    /**
     * 导出
     * @return string
     */
    public function export(): string
    {
        $map = $_GET;
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\Coupon::class);
        $queryTemplateEntity->setWhere($map);
        $data = $this->query->findTemplateAll($queryTemplateEntity);
        $card = '';
        foreach ($data as $d) {
            $card .= $d->code . PHP_EOL;
        }

        ManageLog::log($this->getManage(), "[优惠卷导出]导出优惠卷，共计：" . count($data));
        header('Content-Type:application/octet-stream');
        header('Content-Transfer-Encoding:binary');
        header('Content-Disposition:attachment; filename=优惠卷导出(' . count($data) . ')-' . Date::current() . '.txt');
        return $card;
    }
}