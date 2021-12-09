<?php
declare(strict_types=1);
error_reporting(0);
const BASE_PATH = __DIR__ . "/../";
require(BASE_PATH . '/vendor/autoload.php');
require("Helper.php");

\Kernel\Util\Context::set(\Kernel\Consts\Base::LOCK, (string)file_get_contents(BASE_PATH . "/kernel/Install/Lock"));
//初始化数据库
$capsule = new \Illuminate\Database\Capsule\Manager();
// 创建链接
$capsule->addConnection(config('database'));
// 设置全局静态可访问
$capsule->setAsGlobal();
// 启动Eloquent
$capsule->bootEloquent();


