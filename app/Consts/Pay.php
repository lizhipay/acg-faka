<?php
declare(strict_types=1);

namespace App\Consts;


interface Pay
{
    const IS_SIGN = 0x1;    //是否开启签名验证
    const IS_STATUS = 0x4;  //是否开启状态验证
    const FIELD_STATUS_KEY = 0x2; //状态key
    const FIELD_STATUS_VALUE = 0x3; //状态的值
    const FIELD_ORDER_KEY = 0x5; //订单key
    const FIELD_AMOUNT_KEY = 0x6; //金额key
    const FIELD_RESPONSE = 0x7; //返回值


    const DAFA = "FROM_PAY_DATA"; //回调数据上下文
}