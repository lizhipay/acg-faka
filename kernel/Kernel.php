<?php
declare(strict_types=1);

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
session_start();
try {
    if (!isset($_GET['s'])) {
        $_GET['s'] = "/user/index/index";
    } elseif (trim($_GET['s'], "/") == 'admin') {
        header('location:' . "/admin/authentication/login");
    }

    $s = explode("/", trim((string)$_GET['s'], '/'));
    \Kernel\Util\Context::set(\Kernel\Consts\Base::ROUTE, "/" . implode("/", $s));
    \Kernel\Util\Context::set(\Kernel\Consts\Base::LOCK, (string)file_get_contents(BASE_PATH . "/kernel/Install/Lock"));
    \Kernel\Util\Context::set(\Kernel\Consts\Base::IS_INSTALL, file_exists(BASE_PATH . '/kernel/Install/Lock'));
    \Kernel\Util\Context::set(\Kernel\Consts\Base::OPCACHE, extension_loaded("Zend OPcache") || extension_loaded("opcache"));
    \Kernel\Util\Context::set(\Kernel\Consts\Base::STORE_STATUS, file_exists(BASE_PATH . "/kernel/Plugin.php"));


    $count = count($s);
    $controller = "App\\Controller";
    $ends = end($s);

    if (strtolower($s[0]) == "plugin") {
        $controller = "App";
        \Kernel\Util\Plugin::$currentControllerPluginName = ucfirst(trim((string)$s[1]));
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
    $_GET["_PARAMETER"] = $parameter;


    //初始化数据库
    $capsule = new \Illuminate\Database\Capsule\Manager();
    // 创建链接
    $capsule->addConnection(config('database'));
    // 设置全局静态可访问
    $capsule->setAsGlobal();
    // 启动Eloquent
    $capsule->bootEloquent();

    //插件库
    if (\Kernel\Util\Context::get(\Kernel\Consts\Base::STORE_STATUS) && \Kernel\Util\Context::get(\Kernel\Consts\Base::IS_INSTALL)) {
        require("Plugin.php");
        //插件初始化
        \Kernel\Util\Plugin::scan();
        Initialize();
        //插件初始化
        hook(\App\Consts\Hook::KERNEL_INIT);
    }

    //检测类是否存在
    if (!class_exists($controller)) {
        throw new \Kernel\Exception\NotFoundException("404 Not Found");
    }

    $controllerInstance = new $controller;
    //#Class Interceptor
    $interceptors = [];
    $ref = new ReflectionClass($controllerInstance);
    $reflectionAttributes = $ref->getAttributes();

    foreach ($reflectionAttributes as $attribute) {
        $newInstance = $attribute->newInstance();
    }

    //#Method Interceptor
    $params = [];
    $methodRef = new ReflectionMethod($controllerInstance, $action);
    $methodReflectionAttributes = $methodRef->getAttributes();
    foreach ($methodReflectionAttributes as $attribute) {
        $newInstance = $attribute->newInstance();
    }

    #param
    foreach ($methodRef->getParameters() as $param) {
        $reflectionParamAttributes = $param->getAttributes();
        $paramType = $param->getType()->getName();
        $allowsNull = $param->allowsNull();
        foreach ($reflectionParamAttributes as $reflectionParamAttribute) {
            $paramIns = $reflectionParamAttribute->newInstance();
            if ($paramIns instanceof \Kernel\Annotation\Post) {
                if (!$allowsNull) {
                    if (!key_exists($param->getName(), $_POST)) {
                        throw new \Kernel\Exception\ParameterMissException("{$param->getName()} can not be empty");
                    }
                }
                @$params[] = dat($paramType, $_POST[$param->getName()]);
            } elseif ($paramIns instanceof \Kernel\Annotation\Get) {
                if (!$allowsNull) {
                    if (!key_exists($param->getName(), $_GET)) {
                        throw new \Kernel\Exception\ParameterMissException("{$param->getName()} can not be empty:" . $methodRef->getName());
                    }
                }
                @$params[] = dat($paramType, $_GET[$param->getName()]);
            }
        }
    }

    $reflectionProperties = $ref->getProperties();
    foreach ($reflectionProperties as $property) {
        $reflectionProperty = new \ReflectionProperty($controllerInstance, $property->getName());
        $reflectionPropertiesAttributes = $reflectionProperty->getAttributes();
        foreach ($reflectionPropertiesAttributes as $reflectionAttribute) {
            $ins = $reflectionAttribute->newInstance();
            if ($ins instanceof \Kernel\Annotation\Inject) {
                di($controllerInstance);
            }
        }
    }

    hook(\App\Consts\Hook::CONTROLLER_CALL_BEFORE, $controllerInstance, $action);
    $result = call_user_func_array([$controllerInstance, $action], $params);
    hook(\App\Consts\Hook::CONTROLLER_CALL_AFTER, $controllerInstance, $action, $result);
    hook(\App\Consts\Hook::HTTP_ROUTE_RESPONSE, trim($_GET['s'], "/"), $result);

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
    if ($e instanceof \Kernel\Exception\NotFoundException) {
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
