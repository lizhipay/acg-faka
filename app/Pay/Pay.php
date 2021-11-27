<?php
declare(strict_types=1);

namespace App\Pay;

use App\Entity\PayEntity;

interface Pay
{
    /**
     * 跳转到支付地址
     */
    const TYPE_REDIRECT = 2;

    /**
     * 本地渲染支付页
     */
    const TYPE_LOCAL_RENDER = 3;

    /**
     * 通过POST表单进行提交
     */
    const TYPE_SUBMIT = 4;

    /**
     * 下单，返回支付实体
     * @return PayEntity
     */
    public function trade(): PayEntity;
}