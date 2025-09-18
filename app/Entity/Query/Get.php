<?php
declare (strict_types=1);

namespace App\Entity\Query;

class Get
{
    /**
     * 查询模型
     * @var string
     */
    public string $model;


    /**
     * 分页信息
     * @var array|null
     */
    public ?array $paginate = null;

    /**
     * 查询条件
     * @var array
     */
    public array $where = [];

    /**
     * 排序
     * @var array
     */
    public array $orderBy = ['id', 'desc'];

    /**
     * 显示字段
     * @var array
     */
    public array $columns = ['*'];


    /**
     * @var array
     */
    public array $leftJoinWhere = [];

    /**
     * @param string $class
     */
    public function __construct(string $class)
    {
        $this->model = $class;
    }

    /**
     * 设置分页信息
     * @param int $page
     * @param int $limit
     * @return void
     */
    public function setPaginate(int $page, int $limit = 15): void
    {
        $this->paginate = [$page, $limit];
    }

    /**
     * @param array $where
     * @return void
     */
    public function setWhere(array $where): void
    {
        $map = [];
        foreach ($where as $key => $value) {
            if ($value !== '' && is_scalar($value)) {
                $keys = explode('·', urldecode($key));
                $map[$keys[0]] = $value;
            } else if (!is_scalar($value)) {
                $map[$key] = $value;
            }
        }
        $this->where = $map;
    }

    /**
     * @param string $column
     * @param string $rule
     * @return void
     */
    public function setOrderBy(string $column, string $rule = 'desc'): void
    {
        $this->orderBy = [$column, $rule];
    }


    /**
     * @param array $columns
     * @return void
     */
    public function setColumn(string ...$columns): void
    {
        $this->columns = $columns;
    }

    /**
     * @param array $whereColumns
     * @param string $related
     * @param string $foreignKey
     * @param string $localKey
     * @return void
     */
    public function setWhereLeftJoin(string $related, string $foreignKey, string $localKey, array $whereColumns): void
    {
        $this->leftJoinWhere[] = [
            'columns' => $whereColumns,
            'related' => $related,
            'foreignKey' => $foreignKey,
            'localKey' => $localKey
        ];
    }
}