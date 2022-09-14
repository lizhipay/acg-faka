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
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Card extends Manage
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
        $queryTemplateEntity->setModel(\App\Model\Card::class);
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
            'order' => function (Relation $relation) {
                $relation->select(["id", "trade_no"]);
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
        $commodityId = (int)$_POST['commodity_id'];
        $race = (string)$_POST['race'];

        if ($commodityId == 0) {
            throw new JSONException('(`･ω･´)请选择商品');
        }
        $cards = trim(trim((string)$_POST['secret']), PHP_EOL);
        //进行批量插入
        if ($cards == '') {
            throw new JSONException('(`･ω･´)请至少添加1条卡密信息哦');
        }

        $cards = urldecode($cards);
        $cards = explode(PHP_EOL, $cards);
        $count = count($cards);

        $success = 0;
        $error = 0;
        $date = Date::current();

        $unique = (bool)$_POST['unique'];

        foreach ($cards as $card) {
            $cardt = trim(trim($card), PHP_EOL);
            if ($cardt == "") {
                $error++; //error ++
                continue;
            }

            $pattern = "/#\[([\s\S]+?)\]#/";
            preg_match($pattern, $cardt, $cardy);
            $cardr = preg_replace($pattern, "", $cardt); //卡密

            if ($unique) {
                if (\App\Model\Card::query()->where("secret", "$cardr")->first()) {
                    $error++; //error ++
                    continue;
                }
            }

            $cardObj = new \App\Model\Card();
            $cardObj->commodity_id = $commodityId;
            $cardObj->owner = 0;
            if (isset($_POST['note'])) {
                $cardObj->note = $_POST['note'];
            }
            $cardObj->status = 0;

            if (isset($cardy[1])) {
                //预选信息
                $cardObj->draft = $cardy[1];
            }
            $cardObj->secret = $cardr;
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


        ManageLog::log($this->getManage(), "[导入卡密]共计导入:{$count}张卡密，成功:{$success}张，失败：{$error}张");
        return $this->json(200, "共计导入:{$count}张卡密，成功:{$success}张，失败：{$error}张");
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function edit(): array
    {
        $map = $_POST;
        $createObjectEntity = new CreateObjectEntity();
        $createObjectEntity->setModel(\App\Model\Card::class);
        $createObjectEntity->setMap($map);
        $save = $this->query->createOrUpdateTemplate($createObjectEntity);
        if (!$save) {
            throw new JSONException("保存失败");
        }

        ManageLog::log($this->getManage(), "[修改卡密]编辑了卡密信息");
        return $this->json(200, '（＾∀＾）保存成功');
    }

    /**
     * @return array
     */
    public function lock(): array
    {
        $list = (array)$_POST['list'];
        \App\Model\Card::query()->whereIn('id', $list)->whereRaw("status!=1")->update(['status' => 2]);

        ManageLog::log($this->getManage(), "[锁定卡密]批量锁定了卡密信息，共计：" . count($list));
        return $this->json(200, '锁定成功');
    }

    /**
     * @return array
     */
    public function unlock(): array
    {
        $list = (array)$_POST['list'];
        \App\Model\Card::query()->whereIn('id', $list)->whereRaw("status!=1")->update(['status' => 0]);
        ManageLog::log($this->getManage(), "[解锁卡密]批量解锁了卡密信息，共计：" . count($list));
        return $this->json(200, '解锁成功');
    }

    /**
     * @return array
     */
    public function sell(): array
    {
        $list = (array)$_POST['list'];
        \App\Model\Card::query()->whereIn('id', $list)->whereRaw("status!=1")->update(['status' => 1, 'purchase_time' => Date::current()]);
        ManageLog::log($this->getManage(), "[出售卡密]手动出售卡密信息，共计：" . count($list));
        return $this->json(200, '操作成功');
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {
        $deleteBatchEntity = new DeleteBatchEntity();
        $deleteBatchEntity->setModel(\App\Model\Card::class);
        $deleteBatchEntity->setList($_POST['list']);
        $count = $this->query->deleteTemplate($deleteBatchEntity);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }

        ManageLog::log($this->getManage(), "[批量删除]批量删除了卡密，共计：" . count($_POST['list']));
        return $this->json(200, '（＾∀＾）移除成功');
    }


    /**
     * 导出
     * @return string
     */
    public function export(): string
    {
        $map = $_GET;
        $exportStatus = $map['exportStatus'];
        $exportNum = (int)$map['exportNum'];

        unset($map['exportStatus']);
        unset($map['exportNum']);


        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\Card::class);
        $queryTemplateEntity->setWhere($map);

        if ($exportNum > 0) {
            $queryTemplateEntity->setLimit($exportNum);
            $queryTemplateEntity->setPaginate(true);
            $queryTemplateEntity->setPage(1);
            $data = $this->query->findTemplateAll($queryTemplateEntity);
            $data = $data->items();
        } else {
            $data = $this->query->findTemplateAll($queryTemplateEntity);
        }

        $card = '';
        $ids = [];
        foreach ($data as $d) {
            $card .= $d->secret . PHP_EOL;
            $ids[] = $d->id;
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
                $deleteBatchEntity = new DeleteBatchEntity();
                $deleteBatchEntity->setModel(\App\Model\Card::class);
                $deleteBatchEntity->setList($ids);
                $this->query->deleteTemplate($deleteBatchEntity);
            } catch (\Exception $e) {
            }
        } elseif ($exportStatus == 3) {
            \App\Model\Card::query()->whereIn('id', $ids)->whereRaw("status!=1")->update(['status' => 1, 'purchase_time' => Date::current()]);
        }

        ManageLog::log($this->getManage(), "[卡密导出]导出卡密，共计：" . count($data));
        header('Content-Type:application/octet-stream');
        header('Content-Transfer-Encoding:binary');
        header('Content-Disposition:attachment; filename=卡密导出(' . count($data) . ')-' . Date::current() . '.txt');
        return $card;
    }
}