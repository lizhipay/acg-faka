<?php
declare(strict_types=1);

namespace App\Controller\Base;


use App\Util\Context;

abstract class Manage
{
    /**
     * 获取管理员对象数据
     * @return \App\Model\Manage|null
     */
    public function getManage(): ?\App\Model\Manage
    {
        return Context::get(\App\Consts\Manage::SESSION);
    }
}