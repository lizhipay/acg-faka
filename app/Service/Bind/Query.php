<?php
declare(strict_types=1);

namespace App\Service\Bind;

use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use Illuminate\Database\Query\Builder;
use Kernel\Container\Di;
use Kernel\Exception\JSONException;
use Kernel\Exception\NotFoundException;
use Kernel\Exception\RuntimeException;
use Kernel\Util\Date;

class Query implements \App\Service\Query
{


    /**
     * @param string $model
     * @return string
     * @throws \ReflectionException
     */
    private function getTable(string $model): string
    {
        $instance = Di::instance()->make($model);
        return $instance->getTable();
    }

    /**
     * @param mixed $query
     * @param string $type
     * @param string $column
     * @param string $val
     * @return void
     */
    private function setWhere(mixed &$query, string $type, string $column, string $val): void
    {
        switch ($type) {
            case "equal":
                $query = $query->where($column, $val);
                break;
            case "betweenStart":
                $query = $query->where($column, ">=", $val);
                break;
            case "betweenEnd":
                $query = $query->where($column, "<=", $val);
                break;
            case "search":
                $query = $query->where($column, "like", '%' . $val . '%');
                break;
        }
    }

    /**
     * @param Get $get
     * @param callable|null $append
     * @param int $resultType
     * @return mixed
     * @throws NotFoundException
     * @throws \ReflectionException
     */
    public function get(Get $get, ?callable $append = null, int $resultType = self::RESULT_TYPE_ARRAY): mixed
    {
        /**
         * @var Builder $query
         */
        $query = $get->model::query();
        $tableName = $this->getTable($get->model);


        if (count($get->leftJoinWhere) > 0) {
            $get->orderBy[0] = "{$tableName}.{$get->orderBy[0]}";
            if ($get->columns === ["*"]) {
                $get->columns = ["{$tableName}.*"];
            } else {
                foreach ($get->columns as $index => $column) {
                    $get->columns[$index] = "{$tableName}.{$column}";
                }
            }
        }

        foreach ($get->where as $key => $val) {
            if (is_scalar($val)) {
                $val = urldecode((string)$val);
            }
            $key = urldecode($key);
            $args = explode('-', $key);
            if ($val === '') {
                continue;
            }

            $len = count($args);
            if (!in_array($len, [2, 3])) {
                continue;
            }

            $type = $args[0];
            $column = $len == 2 ? $args[1] : "{$args[1]}->{$args[2]}";


            foreach ($get->leftJoinWhere as $jn) {
                $relatedTableName = $this->getTable($jn['related']);
                foreach ($jn['columns'] as $k => $v) {
                    if ($column == $k) {
                        $query = $query->leftJoin($relatedTableName, "{$relatedTableName}.{$jn['foreignKey']}", "=", "{$tableName}.{$jn["localKey"]}");
                        $this->setWhere($query, $type, "{$relatedTableName}.{$v}", $val);
                        continue 3;
                    }
                }
            }

            $this->setWhere($query, $type, $tableName . "." . $column, $val);
        }

        //追加执行
        if (is_callable($append)) {
            $query = call_user_func($append, $query);
        }


        $query = $query->orderBy($get->orderBy[0], $get->orderBy[1])->distinct();

        if ($get->paginate) {
            $paginate = $query->paginate($get->paginate[1], $get->columns, '', $get->paginate[0]);
            if ($resultType === \App\Service\Query::RESULT_TYPE_ARRAY) {
                $paginate = $paginate->toArray();
                return ["list" => $paginate['data'], "total" => $paginate['total']];
            }
            return $paginate;
        }

        $result = $query->get($get->columns);
        if ($resultType === \App\Service\Query::RESULT_TYPE_ARRAY) {
            $data = $result->toArray();
            return ["list" => $data, "total" => count($data)];
        }
        return $result;
    }


    /**
     * @param Save $save
     * @return mixed
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws \ReflectionException
     */
    public function save(Save $save): mixed
    {
        /**
         * @var Builder $query
         */
        $query = $save->model::query();

        $model = $save->id ? $query->find($save->id) : null;
        $modify = false;

        if (!$model) {
            if (!$save->isAddable) {
                throw new RuntimeException("禁止新增");
            }
            $model = new $save->model;
            $save->isAddCreateTime && ($model->create_time = Date::current());
        } else {
            if (!$save->isModifiable) {
                throw new RuntimeException("禁止修改");
            }
            $modify = true;
        }

        $middles = [];

        /**
         * @param string $key
         * @param mixed $value
         * @param array $middles
         * @param mixed $model
         * @param Save $save
         * @return void
         */
        $addColumn = function (string $key, mixed $value, array &$middles, mixed &$model, Save $save) {
            $middle = $save->getMiddle($key);
            if ($middle) {
                $middles[] = ['middle' => $middle, 'data' => $value];
            } else {
                $model->$key = $value;
            }
        };

        foreach ($save->map as $key => $item) {
            if ($modify) {
                if (count($save->modifiableWhitelist) > 0 && !in_array($key, $save->modifiableWhitelist)) {
                    continue;
                }
            } else {
                if (count($save->addWhitelist) > 0 && !in_array($key, $save->addWhitelist)) {
                    continue;
                }
            }
            $addColumn($key, $item, $middles, $model, $save);
        }

        foreach ($save->forceMap as $key => $item) {
            $addColumn($key, $item, $middles, $model, $save);
        }

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

        return $model;
    }

    /**
     * @param Delete $delete
     * @return int
     * @throws JSONException
     * @throws NotFoundException
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function delete(Delete $delete): int
    {
        if (count($delete->list) === 0) {
            throw new JSONException("你还没有选择数据呢(◡ᴗ◡✿)");
        }

        $count = 0;
        foreach ($delete->list as $id) {
            /**
             * @var Builder $query
             */
            $query = $delete->model::query();
            foreach ($delete->where as $where) {
                $query = $query->where(...$where);
            }

            if ($query->where("id", $id)->first()?->delete()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array $map
     * @param string $field
     * @param string $rule
     * @return array
     */
    public function getOrderBy(array $map, string $field, string $rule = 'desc'): array
    {
        if (!empty($map['sort_field']) && !empty($map['sort_rule'])) {
            return [$map['sort_field'], $map['sort_rule']];
        }
        return [$field, $rule];
    }
}