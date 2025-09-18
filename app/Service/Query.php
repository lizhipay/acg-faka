<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use Kernel\Annotation\Bind;

#[Bind(class: \App\Service\Bind\Query::class)]
interface Query
{
    public const RESULT_TYPE_ARRAY = 0;
    public const RESULT_TYPE_RAW = 4;

    /**
     * @param Get $get
     * @param callable|null $append
     * @param int $resultType
     * @return mixed
     */
    public function get(Get $get, ?callable $append = null, int $resultType = self::RESULT_TYPE_ARRAY): mixed;

    /**
     * @param Save $save
     * @return mixed
     */
    public function save(Save $save): mixed;

    /**
     * @param Delete $delete
     * @return int
     */
    public function delete(Delete $delete): int;


    /**
     * @param array $map
     * @param string $field
     * @param string $rule
     * @return array
     */
    public function getOrderBy(array $map, string $field, string $rule = 'desc'): array;
}