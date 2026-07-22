<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Entity\CreateObjectEntity;
use App\Entity\DeleteBatchEntity;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\Business;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Query;
use App\Util\Date;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor([Waf::class, UserSession::class, Business::class], Interceptor::TYPE_API)]
class Coupon extends User
{
    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $get = new Get(\App\Model\Coupon::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($_POST);
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->where("owner", $this->getUser()->id)->with([
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
        });

        return $this->json(data: $data);
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function save(): array
    {
        $prefix = (string)($_POST['prefix'] ?? ''); //代券前缀
        $note = (string)($_POST['note'] ?? ''); //备注信息
        $commodityId = (int)($_POST['commodity_id'] ?? 0); //商品ID
        $expireTime = (string)($_POST['expire_time'] ?? '');//到期时间
        $money = (float)($_POST['money'] ?? 0); //金额
        $num = (int)($_POST['num'] ?? 0); //生成数量
        $life = (int)($_POST['life'] ?? 0); //可用次数
        $mode = (int)($_POST['mode'] ?? 0); //抵扣模式
        $categoryId = (int)($_POST['category_id'] ?? 0); //分类ID

        $raceGetMode = (int)($_POST['race_get_mode'] ?? 0);
        $race = (string)($raceGetMode === 0 ? ($_POST['race'] ?? '') : ($_POST['race_input'] ?? ''));
        $sku = (array)($_POST['sku'] ?? []);

        $userId = $this->getUser()->id;

        if ($money <= 0) {
            throw new JSONException("请输入有效的代券面值");
        }

        if ($mode === 1 && $money > 1) {
            throw new JSONException("百分比抵扣请填写 0 到 1 之间的小数");
        }

        if ($expireTime != '' && strtotime($expireTime) < time()) {
            throw new JSONException("代券的过期时间不能早于当前时间");
        }

        if ($num < 1 || $num > 1000) {
            throw new JSONException("每次只能生成 1 到 1000 张代券");
        }

        if ($life <= 0) {
            throw new JSONException("代券可用次数至少为 1 次");
        }


        // 指定商品时以商品为唯一抵扣范围，避免同时保存分类导致前端含义不明确。
        if ($commodityId > 0) {
            $categoryId = 0;
        }

        $date = Date::current();
        $result = DB::transaction(function () use (
            $categoryId,
            $commodityId,
            $userId,
            $num,
            $prefix,
            $date,
            $expireTime,
            $money,
            $note,
            $life,
            $mode,
            $sku,
            $race
        ): array {
            if ($categoryId > 0) {
                $category = \App\Model\Category::query()
                    ->where('owner', (int)$userId)
                    ->where('id', $categoryId)
                    ->lockForUpdate()
                    ->first();
                if (!$category) {
                    throw new JSONException('分类不存在');
                }
            }
            if ($commodityId > 0) {
                $commodity = \App\Model\Commodity::query()
                    ->where('owner', (int)$userId)
                    ->where('id', $commodityId)
                    ->lockForUpdate()
                    ->first();
                if (!$commodity) {
                    throw new JSONException('商品不存在');
                }
            }

            $success = 0;
            $error = 0;
            $codes = '';
            for ($i = 0; $i < $num; $i++) {
                $voucher = new \App\Model\Coupon();
                $voucher->code = $prefix . strtoupper(Str::generateRandStr(16));
                $voucher->commodity_id = $commodityId;
                $voucher->category_id = $categoryId;
                $voucher->owner = $userId;
                $voucher->create_time = $date;
                if ($expireTime !== '') {
                    $voucher->expire_time = $expireTime;
                }
                $voucher->money = $money;
                $voucher->status = 0;
                $voucher->note = $note;
                $voucher->life = $life;
                $voucher->mode = $mode;
                $voucher->sku = $sku;
                if ($race !== '') {
                    $voucher->race = $race;
                }
                try {
                    $voucher->save();
                    $success++;
                    $codes .= $voucher->code . PHP_EOL;
                } catch (\Exception) {
                    $error++;
                }
            }

            return ['success' => $success, 'error' => $error, 'code' => $codes];
        });

        return $this->json(
            200,
            "生成完毕，成功:{$result['success']}张，失败：{$result['error']}张",
            $result
        );
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function edit(): array
    {
        $map = $_POST;

        $id = (int)$_POST['id'];

        if ($id <= 0) {
            throw new JSONException("error");
        }

        if (!\App\Model\Coupon::query()->where("owner", $this->getUser()->id)->where("id", $id)->exists()) {
            throw new JSONException("代券不存在");
        }

        $save = new Save(\App\Model\Coupon::class);
        $save->setMap($map, ["status"]);
        $save->disableAddable();
        $save = $this->query->save($save);
        if (!$save) {
            throw new JSONException("保存失败");
        }

        return $this->json(200, '（＾∀＾）保存成功');
    }

    /**
     * @return array
     */
    public function lock(): array
    {
        $list = (array)$_POST['list'];
        \App\Model\Coupon::query()->where("owner", $this->getUser()->id)->whereIn('id', $list)->whereRaw("status!=1")->update(['status' => 2]);
        return $this->json(200, '锁定成功');
    }

    /**
     * @return array
     */
    public function unlock(): array
    {
        $list = (array)$_POST['list'];
        \App\Model\Coupon::query()->where("owner", $this->getUser()->id)->whereIn('id', $list)->whereRaw("status!=1")->update(['status' => 0]);
        return $this->json(200, '解锁成功');
    }


    /**
     * @return array
     */
    public function del(): array
    {
        $list = (array)$_POST['list'];
        \App\Model\Coupon::query()->where("owner", $this->getUser()->id)->whereIn('id', $list)->delete();
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
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->where("owner", $this->getUser()->id);
        });
        $card = '';
        foreach ($data['list'] as $d) {
            $card .= $d['code'] . PHP_EOL;
        }
        header('Content-Type:application/octet-stream');
        header('Content-Transfer-Encoding:binary');
        header('Content-Disposition:attachment; filename=代券导出(' . count($data['list']) . ')-' . Date::current() . '.txt');
        return $card;
    }
}
