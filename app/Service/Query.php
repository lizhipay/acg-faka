<?php
declare(strict_types=1);

namespace App\Service;


use App\Entity\CreateObjectEntity;
use App\Entity\DeleteBatchEntity;
use App\Entity\QueryTemplateEntity;

interface Query
{
    /**
     * 智能查询模型
     * @param QueryTemplateEntity $queryTemplateEntity
     * @param $cloneQuery
     * @return mixed
     */
    public function findTemplateAll(QueryTemplateEntity $queryTemplateEntity, &$cloneQuery = null): mixed;


    /**
     * 智能创建或修改模型
     * @param CreateObjectEntity $createObjectEntity
     * @return array|bool
     */
    public function createOrUpdateTemplate(CreateObjectEntity $createObjectEntity): bool|array;


    /**
     * 自动删除主键模型
     * @param DeleteBatchEntity $batchEntity
     * @return int
     */
    public function deleteTemplate(DeleteBatchEntity $batchEntity): int;
}