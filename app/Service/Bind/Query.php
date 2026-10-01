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
     * 允许出现在客户端 `<操作符>-<列>` 过滤/排序里的操作符白名单。
     * 大小写不符（如 EQUAL）与未知操作符一律丢弃，避免落进未定义分支。
     */
    private const FILTER_OPERATORS = ['equal', 'betweenStart', 'betweenEnd', 'search'];

    /**
     * 自动推导列白名单时**永远排除**的敏感列——即使某表真有这些列，也不许客户端拿它们做
     * 过滤/排序（`betweenStart-password` 布尔预言机、`sort_field=password` 排序侧信道）。
     * 接口如确有正当需要，只能通过 {@see Get::setFilterColumns} 显式声明（那是接口自己的选择）。
     */
    private const SENSITIVE_COLUMNS = ['password', 'salt', 'app_key', 'google_secret', 'totp_secret', 'totp_recovery'];

    /**
     * 表 → 真实列清单缓存（每进程一次 introspection，schema 进程内稳定）。
     * @var array<string, string[]>
     */
    private static array $columnCache = [];

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
     * 取实体表的真实列清单（剔除敏感列），用作**未显式设白名单**接口的默认过滤/排序列白名单。
     * 拿不到（introspection 失败）返回空数组——调用方据此退回「只做格式/标量校验」的宽松模式，
     * 绝不因此把正常过滤全部误伤。
     *
     * @param mixed $query 该模型的查询构造器（用于取连接/schema）
     * @return string[]
     */
    private function tableColumns(string $tableName, mixed $query): array
    {
        if (array_key_exists($tableName, self::$columnCache)) {
            return self::$columnCache[$tableName];
        }
        $cols = [];
        try {
            $cols = $query->getConnection()->getSchemaBuilder()->getColumnListing($tableName);
            $cols = array_values(array_diff($cols, self::SENSITIVE_COLUMNS));
        } catch (\Throwable) {
            $cols = [];
        }
        return self::$columnCache[$tableName] = $cols;
    }

    /**
     * 校验单条排序 [列, 方向]：方向只认 asc/desc；列名必须是合法标识符，且在有可信列清单时必须命中，
     * 否则一律回落主键 id（防 sort_field 任意列→orderBy 非法 SQL→500，以及对敏感列的排序侧信道）。
     *
     * @param array $order [列, 方向]
     * @param string[] $allowed 允许排序的列（空=introspection 不可用，只做格式校验的宽松模式）
     * @param array<string,bool> $joinAliasSet leftJoin 别名集合（接口声明，可信）
     * @param string $tableName
     * @return array{0:string,1:string}
     */
    private function safeOrderBy(array $order, array $allowed, array $joinAliasSet, string $tableName): array
    {
        $column = (string)($order[0] ?? 'id');
        $rule = strtolower((string)($order[1] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $prefixed = str_contains($column, '.');
        $bare = $prefixed ? substr((string)strrchr($column, '.'), 1) : $column;
        $fallback = $prefixed ? substr($column, 0, (int)strrpos($column, '.') + 1) . 'id' : 'id';

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $bare)) {
            return [$fallback, $rule];
        }
        if (!empty($allowed)) {
            $ok = isset($joinAliasSet[$bare]) || isset($joinAliasSet[$column])
                || $bare === 'id' || in_array($bare, $allowed, true);
            if (!$ok) {
                return [$fallback, $rule];
            }
        }
        return [$column, $rule];
    }

    /**
     * 该列在模型里是否被显式声明为数组/JSON 类型（可安全接收非标量值）。
     * @param mixed $model
     * @param string $key
     * @return bool
     */
    private function isArrayCastColumn(mixed $model, string $key): bool
    {
        if (!is_object($model) || !method_exists($model, 'hasCast')) {
            return false;
        }
        return $model->hasCast($key, [
            'array', 'json', 'object', 'collection',
            'encrypted:array', 'encrypted:json', 'encrypted:object', 'encrypted:collection',
        ]);
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
            foreach ($get->thenOrderBy as $index => $then) {
                $get->thenOrderBy[$index][0] = "{$tableName}.{$then[0]}";
            }
            if ($get->columns === ["*"]) {
                $get->columns = ["{$tableName}.*"];
            } else {
                foreach ($get->columns as $index => $column) {
                    $get->columns[$index] = "{$tableName}.{$column}";
                }
            }
        }

        //leftJoin 别名是接口自己声明的（可信），单独放行；其余客户端过滤列走「显式白名单 ?? 真实列」。
        $joinAliasSet = [];
        foreach ($get->leftJoinWhere as $jn) {
            foreach (array_keys($jn['columns']) as $alias) {
                $joinAliasSet[(string)$alias] = true;
            }
        }
        $allowedFilter = $get->filterColumns ?? $this->tableColumns($tableName, $query);

        foreach ($get->where as $key => $val) {
            //数组/对象型过滤值：跳过。where($column, [...]) 会生成非法 SQL → PDOException → 500（F-40/F-41 同源）。
            if (!is_scalar($val)) {
                continue;
            }
            $val = urldecode((string)$val);
            $key = urldecode((string)$key);
            if ($val === '') {
                continue;
            }

            $args = explode('-', $key);
            $len = count($args);
            if (!in_array($len, [2, 3], true)) {
                continue;
            }

            //操作符必须命中白名单（含挡大小写不符的 EQUAL：原本会静默丢条件返回全量）。
            $type = $args[0];
            if (!in_array($type, self::FILTER_OPERATORS, true)) {
                continue;
            }

            //列名 / JSON 子键必须是合法标识符：挡住 ` id`/`id ` 空白键与 JSON 路径注入 → 非法 SQL → 500（F-65）。
            $baseColumn = $args[1];
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $baseColumn)) {
                continue;
            }
            if ($len === 3 && !preg_match('/^[A-Za-z0-9_]+$/', $args[2])) {
                continue;
            }
            $column = $len == 2 ? $baseColumn : "{$baseColumn}->{$args[2]}";

            //列白名单：leftJoin 别名直接放行；其余列必须命中允许集。allowedFilter 为空=introspection 不可用，
            //退回「只做上面格式/标量校验」的宽松模式，绝不因此把正常过滤全部误伤。命中失败=静默丢弃该条件，
            //这样任意列名/不存在列返回的响应都一致，既不 500 也不构成列存在性/布尔预言机（F-03/F-09/F-41）。
            if (!isset($joinAliasSet[$column]) && !isset($joinAliasSet[$baseColumn])
                && !empty($allowedFilter) && !in_array($baseColumn, $allowedFilter, true)) {
                continue;
            }

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


        //排序列校验：主排序受客户端 sort_field 影响（见 getOrderBy），必须落在允许集内，否则回落主键——
        //否则任意列名→orderBy 非法 SQL→500，且可对敏感列（如下级 user.password）做排序侧信道（F-48）。
        //次级排序虽由服务端设置，一并按同口径校验，纵深防御。
        $get->orderBy = $this->safeOrderBy($get->orderBy, $allowedFilter, $joinAliasSet, $tableName);
        foreach ($get->thenOrderBy as $i => $then) {
            $get->thenOrderBy[$i] = $this->safeOrderBy($then, $allowedFilter, $joinAliasSet, $tableName);
        }

        $query = $query->orderBy($get->orderBy[0], $get->orderBy[1]);
        foreach ($get->thenOrderBy as [$thenColumn, $thenRule]) {
            $query = $query->orderBy($thenColumn, $thenRule);
        }
        $query = $query->distinct();

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
                return;
            }
            //非标量值只允许写进「模型显式声明为数组/JSON 的列」或中间表关系。否则像 card.secret / note 这类
            //字符串列会被写成 `["y"]`（数组被 JSON 序列化落库），污染卡密/备注等交付内容（F-40）。既不是中间表、
            //又没声明成数组类型的列，一律跳过（null 保留，用于清空 nullable 列）。
            if (!is_scalar($value) && $value !== null && !$this->isArrayCastColumn($model, $key)) {
                return;
            }
            $model->$key = $value;
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