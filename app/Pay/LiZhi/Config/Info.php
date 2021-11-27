<?php
declare (strict_types=1);

return [
    'version' => '1.0.0',
    'name' => '荔枝付',
    'author' => '荔枝',
    'website' => '#',
    'description' => '支持所有基于"荔枝付"接口协议的平台。',
    'options' => [
        101 => '支付宝-H5',
        105 => '支付宝-当面付',
        106 => '微信-扫码',
        107 => '微信-转账',
        108 => '支付宝-PC扫码',
        109 => '支付宝-WAP支付'
    ],
    'callback' => [
        \App\Consts\Pay::IS_SIGN => true,
        \App\Consts\Pay::IS_STATUS => true,
        \App\Consts\Pay::FIELD_STATUS_KEY => 'status',
        \App\Consts\Pay::FIELD_STATUS_VALUE => 1,
        \App\Consts\Pay::FIELD_ORDER_KEY => 'out_trade_no',
        \App\Consts\Pay::FIELD_AMOUNT_KEY => 'amount',
        \App\Consts\Pay::FIELD_RESPONSE => 'success'
    ]
];