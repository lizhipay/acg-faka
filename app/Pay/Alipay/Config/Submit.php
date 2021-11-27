<?php
declare (strict_types=1);

return [
    [
        "title" => "应用ID",
        "name" => "app_id",
        "type" => "input",
        "placeholder" => "支付宝中创建的应用ID"
    ],
    [
        "title" => "支付宝公钥",
        "name" => "public_key",
        "type" => "textarea",
        "placeholder" => "不是应用公钥哦！一定要是支付宝公钥！"
    ],
    [
        "title" => "应用私钥",
        "name" => "private_key",
        "type" => "textarea",
        "placeholder" => "应用私钥（你自己生成的那个）"
    ]
];