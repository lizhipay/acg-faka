<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\Business;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Query;
use App\Util\Date;
use App\Util\Ini;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor([Waf::class, UserSession::class, Business::class], Interceptor::TYPE_API)]
class Card extends User
{
    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $get = new Get(\App\Model\Card::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($_POST);
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->where("owner", $this->getUser()->id)->with([
                'commodity' => function (Relation $relation) {
                    $relation->select(["id", "cover", "name"]);
                },
                'order' => function (Relation $relation) {
                    $relation->select(["id", "trade_no"]);
                }
            ]);
        });

        return $this->json(data: $data);
    }

    /**
     * @param int $commodityId
     * @return array
     * @throws JSONException
     */
    public function sku(int $commodityId): array
    {
        $commodity = \App\Model\Commodity::query()->where("owner", $this->getUser()->id)->find($commodityId);
        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        $config = Ini::toArray($commodity->config ?: "");

        return $this->json(data: $config);
    }

    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function save(Request $request): array
    {
        $commodityId = $request->post("commodity_id", Filter::INTEGER);
        $raceGetMode = $request->post("race_get_mode", Filter::INTEGER);
        $race = $raceGetMode == 1 ? $request->post("race_input", Filter::NORMAL) : $request->post("race", Filter::NORMAL);
        $sku = $request->post("sku", Filter::NORMAL) ?: [];
        $cardType = $request->post("card_type", Filter::INTEGER);

        if ($commodityId == 0) {
            throw new JSONException('(`･ω･´)请选择商品');
        }

        if (!\App\Model\Commodity::query()->where("owner", $this->getUser()->id)->where("id", $commodityId)->exists()) {
            throw new JSONException('(`･ω･´)商品不存在');
        }

        $cards = trim(trim((string)$request->post("secret", Filter::NORMAL)), PHP_EOL);

        //进行批量插入
        if ($cards == '') {
            throw new JSONException('(`･ω･´)请至少添加1条卡密信息哦');
        }

        $cards = explode(PHP_EOL, $cards);
        $count = count($cards);

        $success = 0;
        $error = 0;
        $date = Date::current();

        $unique = (bool)$_POST['unique'];
        $userId = $this->getUser()->id;

        foreach ($cards as $card) {
            $cardt = trim(trim($card), PHP_EOL);
            if ($cardt == "") {
                $error++; //error ++
                continue;
            }

            $cardObj = new \App\Model\Card();

            if ($cardType == 0) {
                $cardObj->secret = $cardt;
            } else {
                //分割
                $list = explode("║", $cardt);
                if (count($list) < 2) {
                    $error++; //error ++
                    continue;
                }
                $cardObj->secret = trim($list[0]);

                //预选信息
                if (isset($list[1])) {
                    $cardObj->draft = trim($list[1]);
                }

                //独立加价
                if (isset($list[2])) {
                    $cardObj->draft_premium = (float)$list[2];
                }
            }

            if ($unique) {
                if (\App\Model\Card::query()->where("owner", $userId)->where("secret", $cardObj->secret)->first()) {
                    $error++; //error ++
                    continue;
                }
            }

            $cardObj->commodity_id = $commodityId;
            $cardObj->owner = $userId;
            if (isset($_POST['note'])) {
                $cardObj->note = $_POST['note'];
            }
            $cardObj->status = 0;


            $cardObj->sku = $sku;
            $cardObj->create_time = $date;

            if ($race) {
                $cardObj->race = $race;
            }

            try {
                $cardObj->save();
                $success++;
            } catch (\Exception $e) {
                $error++; //error ++
            }
        }

        return $this->json(200, "共计导入:{$count}张卡密，成功:{$success}张，失败：{$error}张");
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function edit(): array
    {
        $map = $_POST;

        if (!isset($map['id'])) {
            throw new JSONException("卡密不存在");
        }

        if (!\App\Model\Card::query()->where("id", $map['id'])->where("owner", $this->getUser()->id)->exists()) {
            throw new JSONException("卡密不存在");
        }

        $save = new Save(\App\Model\Card::class);
        $save->setMap($map, ["draft", "secret", "note", "draft_premium", "status"]);
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
        \App\Model\Card::query()->whereIn('id', $list)->where("owner", $this->getUser()->id)->whereRaw("status!=1")->update(['status' => 2]);
        return $this->json(200, '锁定成功');
    }

    /**
     * @return array
     */
    public function unlock(): array
    {
        $list = (array)$_POST['list'];
        \App\Model\Card::query()->whereIn('id', $list)->where("owner", $this->getUser()->id)->whereRaw("status!=1")->update(['status' => 0]);
        return $this->json(200, '解锁成功');
    }

    /**
     * @return array
     */
    public function del(): array
    {
        $list = (array)$_POST['list'];
        \App\Model\Card::query()->whereIn('id', $list)->where("owner", $this->getUser()->id)->delete();
        return $this->json(200, '（＾∀＾）移除成功');
    }


    /**
     * @return array
     */
    public function sell(): array
    {
        $list = (array)$_POST['list'];
        \App\Model\Card::query()->whereIn('id', $list)->where("owner", $this->getUser()->id)->whereRaw("status!=1")->update(['status' => 1, 'purchase_time' => Date::current()]);
        return $this->json(200, '操作成功');
    }

    /**
     * 导出
     * @return string
     */
    public function export(): string
    {
        $map = $_GET;
        $exportStatus = $map['export_status'];
        $exportNum = (int)$map['export_num'];
        $note = $map['note'] ?: null;

        unset($map['export_status']);
        unset($map['export_num']);


        $map['equal-owner'] = $this->getUser()->id;
        $get = new Get(\App\Model\Card::class);
        $get->setWhere($map);

        if ($exportNum > 0) {
            $get->setPaginate(1, $exportNum);
            $data = $this->query->get($get);
        } else {
            $data = $this->query->get($get);
        }

        $card = '';
        $ids = [];
        foreach ($data['list'] as $d) {
            $card .= $d['secret'] . PHP_EOL;
            $ids[] = $d['id'];
        }

        if ($note) {
            \App\Model\Card::query()->whereIn('id', $ids)->update(['note' => $note]);
        }

        if ($exportStatus == 1) {
            //锁定卡密
            try {
                \App\Model\Card::query()->whereIn('id', $ids)->whereRaw("status!=1")->update(['status' => 2]);
            } catch (\Exception $e) {
            }
        } elseif ($exportStatus == 2) {
            //删除卡密
            try {
                $deleteBatchEntity = new Delete(\App\Model\Card::class, $ids);
                $this->query->delete($deleteBatchEntity);
            } catch (\Exception $e) {
            }
        } elseif ($exportStatus == 3) {
            \App\Model\Card::query()->whereIn('id', $ids)->whereRaw("status!=1")->update(['status' => 1, 'purchase_time' => Date::current()]);
        }

        header('Content-Type:application/octet-stream');
        header('Content-Transfer-Encoding:binary');
        header('Content-Disposition:attachment; filename=卡密导出(' . count($data) . ')-' . Date::current() . '.txt');
        return $card;
    }
}