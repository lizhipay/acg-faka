<?php
declare(strict_types=1);

namespace App\Service\Impl;


use App\Entity\CreateObjectEntity;
use App\Entity\DeleteBatchEntity;
use App\Entity\QueryTemplateEntity;
use App\Service\Query;
use Illuminate\Database\Query\JoinClause;
use Kernel\Exception\JSONException;

class QueryService implements Query
{
    /**
     * 智能查询模型
     * @param QueryTemplateEntity $queryTemplateEntity
     * @param $cloneQuery
     * @return mixed
     */
    public function findTemplateAll(QueryTemplateEntity $queryTemplateEntity, &$cloneQuery = null): mixed
    {
        $query = $queryTemplateEntity->getModel()::query();
        $with = $queryTemplateEntity->getWith();
        $where = $queryTemplateEntity->getWhere();  // equal_user = xxx
        $order = $queryTemplateEntity->getOrder();
        $field = $queryTemplateEntity->getField();
        $withCount = $queryTemplateEntity->getWithCount();
        $fieldRefactor = false;

        $whereRaw = $queryTemplateEntity->getWhereRaw();
        if ($whereRaw) {
            $query = $query->whereRaw($whereRaw);
        }

        //将where拆分
        foreach ($where as $key => $val) {
            if (is_scalar($val)) {
                $val = urldecode((string)$val);
            }
            $key = urldecode($key);
            $args = explode('-', $key);
            if ($val === '') {
                continue;
            }
            switch ($args[0]) {
                case "equal":
                    $query = $query->where($args[1], $val);
                    break;
                case "betweenStart":
                    $query = $query->where($args[1], ">=", $val);
                    break;
                case "betweenEnd":
                    $query = $query->where($args[1], "<=", $val);
                    break;
                case "search":
                    $query = $query->where($args[1], "like", '%' . $val . '%');
                    break;
                case "middle":
                    //这里将完全改变查询方式
                    $middle = $queryTemplateEntity->getMiddle($args[1]);
                    $val = explode(",", $val);
                    $query = $query->join($middle['middle'], function (JoinClause $join) use ($middle, $val) {
                        $join->on("{$middle['localTable']}.id", '=', "{$middle['middle']}.{$middle['localKey']}")->whereIn("{$middle['middle']}.{$middle['foreignKey']}", $val);
                    });
                    //重构查询字段
                    if (!$fieldRefactor) {
                        foreach ($field as $index => $value) {
                            $field[$index] = $middle['localTable'] . '.' . $value;
                        }
                        $fieldRefactor = true;
                    }
                    break;
            }
        }

        if ($cloneQuery != null) {
            $cloneQuery = clone $query;
        }

        foreach ($with as $w) {
            $query = $query->with($w);
        }

        foreach ($withCount as $wc) {
            $query = $query->withCount($wc);
        }

        $query = $query->orderBy($order['field'], $order['rule'])->distinct();

        if ($queryTemplateEntity->isPaginate()) {
            return $query->paginate($queryTemplateEntity->getLimit(), $field, '', $queryTemplateEntity->getPage());
        }

        return $query->get();
    }


    /**
     * 智能创建或修改模型
     * @param CreateObjectEntity $createObjectEntity
     * @return array|bool
     */
    public function createOrUpdateTemplate(CreateObjectEntity $createObjectEntity): array|bool
    {
        $object = $createObjectEntity->getModel();
        $query = $object::query();
        $map = $createObjectEntity->getMap();
        $model = $query->find((int)$map['id']);
        $createDate = $createObjectEntity->getCreateDate();
        $updateDate = $createObjectEntity->getUpdateDate();
        $new = false;

        if (!$model) {
            $model = new $object;
            $new = true;
        }

        $middles = [];

        foreach ($map as $key => $item) {
            $middle = $createObjectEntity->getMiddle($key);
            if ($middle) {
                $middles[] = ['middle' => $middle, 'data' => $item];
            } else {
                if (is_scalar($item)) {
                    $item = urldecode($item);
                }
                $model->$key = $item;
            }
        }

        $dateNow = date("Y-m-d H:i:s", time());

        if (!empty($createDate)) {
            $model->$createDate = $dateNow;
        }

        if (!empty($updateDate)) {
            $model->$updateDate = $dateNow;
        }

        try {
            $model->save();
            $id = $model->id;
            foreach ($middles as $m) {
                $middle = $m['middle'];
                $data = $m['data'];
                if (!empty($data)) {
                    //删除中间表关系
                    $middle['middle']::query()->where($middle['localKey'], $id)->delete();
                }
                $localKey = $middle['localKey'];
                $foreignKey = $middle['foreignKey'];
                //重新建立模型关系
                foreach ($data as $datum) {
                    $middleObject = new $middle['middle'];
                    $middleObject->$localKey = $id;
                    $middleObject->$foreignKey = $datum;
                    $middleObject->save();
                }
            }
            if ($new) {
                return ['status' => 0, 'id' => $id];
            }

            return ['status' => 1, 'id' => $id];
        } catch (\Exception $e) {
            return false;
        }
    }


    /**
     * 自动删除主键模型
     * @param DeleteBatchEntity $batchEntity
     * @return int
     * @throws JSONException
     */
    public function deleteTemplate(DeleteBatchEntity $batchEntity): int
    {
        $list = $batchEntity->getList();
        if (!is_array($list) || count($list) == 0) {
            throw new JSONException("你还没有选择数据呢(◡ᴗ◡✿)");
        }
        return $batchEntity->getModel()::destroy($list);
    }
}