<?php
declare (strict_types=1);

return [
    'version' => '1.0.0',
    'name' => '易支付',
    'author' => '荔枝',
    'website' => '#',
    'description' => '支持所有易支付程序',
    'options' => [
        'alipay' => '支付宝',
        'wxpay' => '微信',
        'qqpay' => 'QQ钱包',
    ],
    'callback' => [
        \App\Consts\Pay::IS_SIGN => true,
        \App\Consts\Pay::IS_STATUS => true,
        \App\Consts\Pay::FIELD_STATUS_KEY => 'trade_status',
        \App\Consts\Pay::FIELD_STATUS_VALUE => 'TRADE_SUCCESS',
        \App\Consts\Pay::FIELD_ORDER_KEY => 'out_trade_no',
        \App\Consts\Pay::FIELD_AMOUNT_KEY => 'money',
        \App\Consts\Pay::FIELD_RESPONSE => 'success'
    ]
];