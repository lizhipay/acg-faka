<?php
declare (strict_types=1);

return [
    'version' => '1.0.0',
    'name' => '支付宝-官方',
    'author' => '荔枝',
    'website' => 'https://www.alipay.com',
    'description' => '支付宝 知托付！',
    'options' => [
        1 => '当面付',
        2 => 'PC支付',
        3 => 'WAP支付',
    ],
    'callback' => [
        \App\Consts\Pay::IS_SIGN => true,
        \App\Consts\Pay::IS_STATUS => true,
        \App\Consts\Pay::FIELD_STATUS_KEY => 'trade_status',
        \App\Consts\Pay::FIELD_STATUS_VALUE => 'TRADE_SUCCESS',
        \App\Consts\Pay::FIELD_ORDER_KEY => 'out_trade_no',
        \App\Consts\Pay::FIELD_AMOUNT_KEY => 'total_amount',
        \App\Consts\Pay::FIELD_RESPONSE => 'success'
    ]
];