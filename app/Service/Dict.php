<?php
declare(strict_types=1);

namespace App\Service;


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