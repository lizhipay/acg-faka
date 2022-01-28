<?php
declare(strict_types=1);


return [
    \App\Service\ManageSSO::class => \App\Service\Impl\ManageSSOService::class,
    \App\Service\Dict::class => \App\Service\Impl\DictService::class,
    \App\Service\Upload::class => \App\Service\Impl\UploadService::class,
    \App\Service\Query::class => \App\Service\Impl\QueryService::class,
    \App\Service\Order::class => \App\Service\Impl\OrderService::class,
    \App\Service\Shared::class => \App\Service\Impl\SharedService::class,
    \App\Service\Email::class => \App\Service\Impl\EmailService::class,
    \App\Service\Sms::class => \App\Service\Impl\SmsService::class,
    \App\Service\UserSSO::class => \App\Service\Impl\UserSSOService::class,
    \App\Service\Pay::class => \App\Service\Impl\PayService::class,
    \App\Service\User::class => \App\Service\Impl\UserService::class,
    \App\Service\Recharge::class => \App\Service\Impl\RechargeService::class,
    \App\Service\Cash::class => \App\Service\Impl\CashService::class,
    \App\Service\App::class => \App\Service\Impl\AppService::class,
    \App\Service\Shop::class => \App\Service\Impl\ShopService::class,
];