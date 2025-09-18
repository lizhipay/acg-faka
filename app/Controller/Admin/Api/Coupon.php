<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Service\Query;
use App\Util\Date;
use App\Util\Str;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

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
        $get = new Get(\App\Model\Coupon::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);

        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->with([
                'owner:id,username,avatar',
                'commodity:id,name',
                'category:id,name'
            ]);
        });

        return $this->json(data: $data);
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
        $raceGetMode = (int)$_POST['race_get_mode'];
        $race = $raceGetMode == 0 ? $_POST['race'] : $_POST['race_input'];
        $sku = $_POST['sku'] ?: [];

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
            $voucher->sku = $sku;
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
        $save = new Save(\App\Model\Coupon::class);
        $save->setMap($map);
        $save = $this->query->save($save);
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
        $delete = new Delete(\App\Model\Coupon::class, $_POST['list']);
        $count = $this->query->delete($delete);
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
        $map = $this->request->get(flags: Filter::NORMAL);
        $get = new Get(\App\Model\Coupon::class);
        $get->setWhere($map);
        $data = $this->query->get($get);
        $data = $data['list'];
        $card = '';
        foreach ($data as $d) {
            $card .= $d['code'] . PHP_EOL;
        }
        ManageLog::log($this->getManage(), "[优惠卷导出]导出优惠卷，共计：" . count($data));
        header('Content-Type:application/octet-stream');
        header('Content-Transfer-Encoding:binary');
        header('Content-Disposition:attachment; filename=优惠卷导出(' . count($data) . ')-' . Date::current() . '.txt');
        return $card;
    }
}