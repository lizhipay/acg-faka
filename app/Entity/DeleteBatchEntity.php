<?php


namespace App\Entity;


/**
 * 删除实体
 * Class DeleteBatchEntity
 * @package App\Entity
 */
class DeleteBatchEntity
{
    /**
     * 删除模型
     * @var string
     */
    private $model;

    /**
     * 删除列表
     * @var array
     */
    private $list;

    /**
     * @return string
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * @param string $model
     * @return DeleteBatchEntity
     */
    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    /**
     * @return array
     */
    public function getList()
    {
        return $this->list;
    }

    /**
     * @param array $list
     * @return DeleteBatchEntity
     */
    public function setList($list): self
    {
        $this->list = $list;
        return $this;
    }


}