<?php
declare(strict_types=1);

namespace App\Controller\Base;


use App\Model\UserGroup;
use App\Util\Context;

abstract class Shared
{
    /**
     * 获取用户对象数据
     * @return \App\Model\User|null
     */
    public function getUser(): ?\App\Model\User
    {
        return Context::get(\App\Consts\Shared::SESSION);
    }


    /**
     * @return \App\Model\UserGroup|null
     */
    public function getUserGroup(): ?UserGroup
    {
        $user = $this->getUser();
        if (!$user) {
            return null;
        }
        return UserGroup::get($user->recharge);
    }
}