<?php
declare (strict_types=1);

namespace App\Entity\Query;

use Kernel\Exception\JSONException;

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
     * 次级排序：主排序值相同的行依次按这些列继续排，每项 [列, 方向]。
     * 分页列表的主排序列大量重复时（比如排序值全是 0）必须带上，否则翻页会漏行、重行，拖动排序也无从谈起。
     * @var array<int, array{0:string,1:string}>
     */
    public array $thenOrderBy = [];

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
     * @throws JSONException
     */
    public function setWhere(array $where): void
    {
        $map = [];
        $canonicalSource = [];
        foreach ($where as $key => $value) {
            if ($value !== '' && is_scalar($value)) {
                $canonical = explode('·', urldecode((string)$key))[0];
                //归一化后撞名（如 equal-owner 与 equal-owner·1，或编码别名 %C2%B7 / %25C2%25B7）是越权构造的典型手法：
                //调用方在同一数组里先设好授权条件（如 equal-owner=自己），带后缀的同名键在后面把它覆盖成别人的归属（CWE-639）。
                //合法请求里同一个原始键在 PHP 数组内本就唯一，不可能走到这里，直接拒绝最稳妥；授权仍应由查询构造器独立追加，别只靠这里。
                if (array_key_exists($canonical, $map) && ($canonicalSource[$canonical] ?? null) !== (string)$key) {
                    throw new \Kernel\Exception\JSONException('查询条件存在冲突的参数名');
                }
                $canonicalSource[$canonical] = (string)$key;
                $map[$canonical] = $value;
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
     * 追加一个次级排序（在主排序之后生效）
     * @param string $column
     * @param string $rule
     * @return void
     */
    public function addOrderBy(string $column, string $rule = 'asc'): void
    {
        $this->thenOrderBy[] = [$column, $rule];
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