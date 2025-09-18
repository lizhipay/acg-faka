<?php
declare(strict_types=1);

namespace App\Controller\Base;


use App\Util\Context;
use Kernel\Annotation\Inject;
use Kernel\Context\Interface\Request;

abstract class Manage
{
    #[Inject]
    protected Request $request;

    /**
     * 获取管理员对象数据
     * @return \App\Model\Manage|null
     */
    public function getManage(): ?\App\Model\Manage
    {
        return Context::get(\App\Consts\Manage::SESSION);
    }
}