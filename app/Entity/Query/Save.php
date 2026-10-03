<?php
declare(strict_types=1);

namespace App\Entity\Query;

class Save
{
    /**
     * 创建模型
     * @var string
     */
    public string $model;

    /**
     * @var int|null
     */
    public ?int $id = null;

    /**
     * 数据结构体
     * @var array
     */
    public array $map = [];

    /**
     * @var array
     */
    public array $forceMap = [];


    /**
     * 中间表
     * @var array
     */
    public array $middle = [];


    /**
     * 是否可以修改
     * @var bool
     */
    public bool $isModifiable = true;

    /**
     * 是否可以新增
     * @var bool
     */
    public bool $isAddable = true;

    /**
     * @var bool
     */
    public bool $isAddCreateTime = false;


    /**
     * 新增字段白名单
     * @var array
     */
    public array $addWhitelist = [];

    /**
     * 修改字段白名单
     * @var array
     */
    public array $modifiableWhitelist = [];

    /**
     * 允许"清空"的字段：默认 setMap 会跳过空字符串（让"留空=不修改"成立，如密码），
     * 但图标 / 封面 / 发货信息等可选字段，用户确实需要能清空。列入此名单的字段，
     * 当请求里显式带了空值时会被写入（清空），不列入的字段维持原有"空值跳过"行为。
     * @var array
     */
    public array $allowEmpty = [];


    /**
     * @param string $model
     */
    public function __construct(string $model)
    {
        $this->model = $model;
    }

    /**
     * @return void
     */
    public function disableModifiable(): void
    {
        $this->isModifiable = false;
    }

    /**
     * @return void
     */
    public function disableAddable(): void
    {
        $this->isAddable = false;
    }

    /**
     * @return void
     */
    public function enableCreateTime(): void
    {
        $this->isAddCreateTime = true;
    }

    /**
     * @param array $map
     * @param array $bypass
     * @param array $forbidden
     * @return void
     */
    public function setMap(array $map, array $bypass = [], array $forbidden = []): void
    {
        if ($this->id === null) {
            $this->id = (isset($map['id']) && is_numeric($map['id'])) ? (int)$map['id'] : null;
        }

        foreach ($map as $key => $value) {
            $key = strtolower(trim((string)$key));
            //空字符串默认跳过（"留空=不修改"，如密码）；但列入 allowEmpty 的字段允许被清空写入
            if (($value === '' && !in_array($key, $this->allowEmpty, true)) || $key == "id" || (!in_array($key, $bypass) && !empty($bypass))) {
                continue;
            }

            if (in_array($key, $forbidden) && !empty($forbidden)) {
                continue;
            }

            if (is_scalar($value)) {
                $this->addMap($key, trim((string)$value));
                continue;
            }

            $this->addMap($key, $value);
        }
    }

    /**
     * @param int $id
     */
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function addMap(string $name, mixed $value): void
    {
        if (isset($this->map[$name])) {
            return;
        }
        $this->map[$name] = $value;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function addForceMap(string $name, mixed $value): void
    {
        if (isset($this->forceMap[$name])) {
            return;
        }
        $this->forceMap[$name] = $value;
    }

    /**
     * @param string $key
     * @return array|null
     */
    public function getMiddle(string $key): ?array
    {
        if (!array_key_exists($key, $this->middle)) {
            return null;
        }
        return $this->middle[$key];
    }

    /**
     * @param string $key
     * @param string $middle
     * @param string $foreignKey
     * @param string $localKey
     * @return void
     */
    public function setMiddle(string $key, string $middle, string $foreignKey, string $localKey): void
    {
        $this->middle[$key] = [
            'middle' => $middle,
            'foreignKey' => $foreignKey,
            'localKey' => $localKey
        ];
    }

    /**
     * @param string ...$column
     * @return void
     */
    public function setAddWhitelist(string ...$column): void
    {
        $this->addWhitelist = $column;
    }

    /**
     * @param string ...$column
     * @return void
     */
    public function setModifiableWhitelist(string ...$column): void
    {
        $this->modifiableWhitelist = $column;
    }
}