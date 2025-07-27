<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Kernel\Annotation\Collector;
use Kernel\Consts\Base;
use Kernel\Container\Di;
use Kernel\Context\Request;
use Kernel\Exception\NotFoundException;
use Kernel\Plugin\Hook;
use Kernel\Util\Context;
use Kernel\Util\Plugin;
use Kernel\Waf\Firewall;

error_reporting(0);
const BASE_PATH = __DIR__ . "/../";
require(BASE_PATH . '/vendor/autoload.php');
require("Helper.php");
//define
define("BASE_APP_SERVER", match ((int)config("store")['server']) {
    0 => App\Service\App::MAIN_SERVER,
    1 => App\Service\App::STANDBY_SERVER1,
    2 => App\Service\App::STANDBY_SERVER2,
    3 => App\Service\App::GENERAL_SERVER
});
//session
session_name("ACG-SHOP");
//session_start();
//session_write_close();
try {
    //waf install -> 2025-07-26
    $routePath = $_GET['s'] = $_GET['s'] ?? "/user/index/index";
    Context::set(\Kernel\Context\Interface\Request::class, new Request());
    if (trim($routePath, "/") == 'admin') {
        header('location:' . "/admin/authentication/login");
    }

    $s = explode("/", trim((string)$routePath, '/'));
    Context::set(Base::ROUTE, "/" . implode("/", $s));
    Context::set(Base::LOCK, (string)file_get_contents(BASE_PATH . "/kernel/Install/Lock"));
    Context::set(Base::IS_INSTALL, file_exists(BASE_PATH . '/kernel/Install/Lock'));
    Context::set(Base::OPCACHE, extension_loaded("Zend OPcache") || extension_loaded("opcache"));
    Context::set(Base::STORE_STATUS, file_exists(BASE_PATH . "/kernel/Plugin.php"));

    $count = count($s);
    $controller = "App\\Controller";
    $ends = end($s);

    if (strtolower($s[0]) == "plugin") {
        $controller = "App";
        Plugin::$currentControllerPluginName = ucfirst(trim((string)$s[1]));
    }

    foreach ($s as $j => $x) {
        if ($j == ($count - 1)) {
            break;
        }
        if (strtolower($s[0]) == "plugin" && $j == 2) {
            $controller .= "\\Controller";
        }
        $controller .= '\\' . ucfirst(trim($x));
    }

    //参数
    $parameter = explode('.', $ends);
    //需要执行的方法
    $action = array_shift($parameter);
    //存储
    $_GET["_PARAMETER"] = Firewall::inst()->xssKiller($parameter);

    //初始化数据库
    $capsule = new Manager();
    $db_config = config('database');
    $db_config['options'][PDO::ATTR_PERSISTENT] = true;
    // 创建链接
    $capsule->addConnection($db_config);
    // 设置全局静态可访问
    $capsule->setAsGlobal();
    // 启动Eloquent
    $capsule->bootEloquent();

    //插件库
    if (Context::get(Base::STORE_STATUS) && Context::get(Base::IS_INSTALL)) {
        require("Plugin.php");
        //插件初始化
        Hook::inst()->load();
        //插件初始化
        hook(\App\Consts\Hook::KERNEL_INIT);
    }


    //检测类是否存在
    if (!class_exists($controller)) {
        throw new NotFoundException("404 Not Found");
    }

    $controllerInstance = new $controller;

    //检测method是否存在
    if (!method_exists($controllerInstance, $action)) {
        throw new NotFoundException("404 Not Found");
    }


    Collector::instance()->classParse($controllerInstance, function (\ReflectionAttribute $attribute) {
        $attribute->newInstance();
    });

    Collector::instance()->methodParse($controllerInstance, $action, function (\ReflectionAttribute $attribute) {
        $attribute->newInstance();
    });

    //依赖注入
    Di::instance()->inject($controllerInstance);


    //参数注入
    $parameters = Collector::instance()->getMethodParameters($controllerInstance, $action, $_REQUEST);
    hook(\App\Consts\Hook::CONTROLLER_CALL_BEFORE, $controllerInstance, $action);
    $result = call_user_func_array([$controllerInstance, $action], $parameters);
    hook(\App\Consts\Hook::CONTROLLER_CALL_AFTER, $controllerInstance, $action, $result);
    hook(\App\Consts\Hook::HTTP_ROUTE_RESPONSE, $routePath, $result);


    if ($result === null) {
        return;
    }

    if (!is_scalar($result)) {
        header('content-type:application/json;charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        header("Content-type: text/html; charset=utf-8");
        echo $result;
    }
} catch (Throwable $e) {
    if ($e instanceof NotFoundException) {
        exit(feedback("404 Not Found"));
    } elseif ($e instanceof \Kernel\Exception\ParameterMissException) {
        header('content-type:application/json;charset=utf-8');
        exit(json_encode(["code" => $e->getCode(), "msg" => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } elseif ($e instanceof \Kernel\Exception\JSONException) {
        header('content-type:application/json;charset=utf-8');
        exit(json_encode(["code" => $e->getCode(), "msg" => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } elseif ($e instanceof \Kernel\Exception\ViewException) {
        header("Content-type: text/html; charset=utf-8");
        exit(feedback($e->getFile() . "<br>" . $e->getMessage()));
    } else {
        exit(feedback($e->getFile() . ":" . $e->getLine() . "<br>" . $e->getMessage()));
    }
}
