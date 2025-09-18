<?php
declare(strict_types=1);

namespace App\Entity\Query;

class Delete
{
    /**
     * 删除模型
     * @var string
     */
    public string $model;

    /**
     * 删除列表
     * @var array
     */
    public array $list = [];

    /**
     * @var array
     */
    public array $where = [];

    /**
     * @param string $model
     * @param array|int $list
     */
    public function __construct(string $model, array|int $list)
    {
        $this->model = $model;
        $this->list = is_array($list) ? $list : [$list];
    }

    /**
     * @param string $column
     * @param string|null $operator
     * @param mixed|null $value
     * @param string $boolean
     * @return void
     */
    public function setWhere(string $column, mixed $operator = null, mixed $value = null, mixed $boolean = 'and'): void
    {
        $this->where[] = [$column, (string)$operator, $value, $boolean];
    }
}