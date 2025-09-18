<?php
declare(strict_types=1);

namespace App\Service;


use Kernel\Annotation\Bind;

#[Bind(class: \App\Service\Bind\Dict::class)]
interface Dict
{
    /**
     * 获取字典列表
     * @param string $dictName
     * @param string $keywords
     * @param string $where
     * @return array
     */
    public function get(string $dictName, string $keywords = '', string $where = ''): array;
}