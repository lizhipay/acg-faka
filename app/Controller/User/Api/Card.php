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
use App\Util\Date;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

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
        $map = $_POST;
        $map['equal-owner'] = $this->getUser()->id;
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\Card::class);
        $queryTemplateEntity->setLimit((int)$_POST['limit']);
        $queryTemplateEntity->setPage((int)$_POST['page']);
        $queryTemplateEntity->setPaginate(true);
        $queryTemplateEntity->setWhere($map);
        $queryTemplateEntity->setWith([
            'commodity' => function (Relation $relation) {
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
        $userId = $this->getUser()->id;

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
            $cardObj->owner = $userId;
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
            if ($race){
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
     * @throws JSONException
     */
    public function del(): array
    {
        $list = (array)$_POST['list'];
        \App\Model\Card::query()->whereIn('id', $list)->where("owner", $this->getUser()->id)->delete();
        return $this->json(200, '（＾∀＾）移除成功');
    }


    /**
     * 导出
     * @return string
     */
    public function export(): string
    {
        $map = $_GET;
        $map['equal-owner'] = $this->getUser()->id;
        $exportStatus = $map['exportStatus'];
        unset($map['exportStatus']);
        $queryTemplateEntity = new QueryTemplateEntity();
        $queryTemplateEntity->setModel(\App\Model\Card::class);
        $queryTemplateEntity->setWhere($map);
        $data = $this->query->findTemplateAll($queryTemplateEntity);
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
        }

        header('Content-Type:application/octet-stream');
        header('Content-Transfer-Encoding:binary');
        header('Content-Disposition:attachment; filename=卡密导出(' . count($data) . ')-' . Date::current() . '.txt');
        return $card;
    }
}