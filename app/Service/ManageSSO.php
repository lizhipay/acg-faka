<?php
//@Author: ChunXi <i@acg.sb>
//@License: MIT
//@Date: 2021/7/5
declare(strict_types=1);

namespace App\Service;

/**
 * Interface ManageSSO
 * @package App\Service
 */
interface ManageSSO
{
    /**
     * 登录
     * @param string $username
     * @param string $password
     * @param int $mode
     * @return array
     */
    public function login(string $username, string $password, int $mode): array;
}