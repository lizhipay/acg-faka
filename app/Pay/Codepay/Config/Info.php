<?php
declare (strict_types=1);

return [
    'version' => '1.0.0',
    'name' => '<b style="color: red;">[官方推荐]</b> 码支付',
    'author' => '荔枝',
    'website' => '<a  style="color: green;" href="https://acgpay.losie.net" target="_blank">https://acgpay.losie.net</a>',
    'description' => '<span style="color: green;">免挂机，免金额输入，客户付款直达你的收款账户。</span>',
    'options' => [
        'alipay' => '支付宝',
        'wxpay' => '微信'
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