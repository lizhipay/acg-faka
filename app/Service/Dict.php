<?php
declare(strict_types=1);

namespace App\Service;


use App\Service\Impl\DictService;
use Kernel\Annotation\Bind;

#[Bind(class: DictService::class)]
interface Dict
{
    /**
     * 获取字典列表
     * @param string $dictName
     * @param string $keywords
     * @param string $where
     * @return mixed
     */
    public function get(string $dictName, string $keywords = '', string $where = ''): mixed;
}