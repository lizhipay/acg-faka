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
     * 允许客户端过滤的列白名单。null=不限制（默认，保持既有行为）。
     *
     * 一旦设为数组，{@see \App\Service\Bind\Query::get()} 只接受 `<操作符>-<列>` 里列名在此表内的
     * 客户端过滤条件，其余静默丢弃。用于**匿名/低权限**接口：客户端能任意指定过滤列时，`total`
     * 的 0/1 就是一个布尔预言机——攻击者用 `search-secret` / `betweenStart-secret` 之类逐字符盲注，
     * 能把未售卡密的 secret 拖出来。白名单只放行确实要暴露给该接口的非敏感列（如预选预览 draft）。
     * 注意：只约束来自 {@see setWhere} 的客户端条件，不影响 get() 里服务端自己追加的 append 闭包。
     *
     * @var string[]|null
     */
    public ?array $filterColumns = null;

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
        //钳制分页参数：负数 limit 会让 Laravel forPage 生成 take(-N) 非法 SQL→PDOException→500
        //（免登录接口如 index/commodity、index/card 可直接触发）；过大 limit 则把整表读进内存。
        //负数/0 回落默认 15，上限 100（与 CommodityOrder::data 同口径）；page 至少为 1。
        $limit = $limit > 0 ? min($limit, 100) : 15;
        $this->paginate = [max(1, $page), $limit];
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
     * 限定客户端可过滤的列（白名单）。只影响 {@see setWhere} 带进来的客户端过滤条件，
     * 不影响 get() 的 append 闭包里服务端追加的条件。匿名/低权限接口用它杜绝任意列盲注预言机。
     *
     * @param string[] $columns 允许出现在 `<操作符>-<列>` 里的列名
     * @return void
     */
    public function setFilterColumns(array $columns): void
    {
        $this->filterColumns = $columns;
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