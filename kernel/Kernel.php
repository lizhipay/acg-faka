<?php
declare(strict_types=1);

use App\Util\AdminEntrance;
use Illuminate\Database\Capsule\Manager;
use Kernel\Annotation\Collector;
use Kernel\Consts\Base;
use Kernel\Container\Di;
use Kernel\Context\Request;
use Kernel\Exception\NotFoundException;
use Kernel\Plugin\Hook;
use Kernel\Util\Context;
use Kernel\Util\Plugin;
use Kernel\Util\RequestLogger;
use Kernel\Waf\Firewall;


date_default_timezone_set("Asia/Shanghai");
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
define("APP_VERSION", config('app')['version']);

//session
session_name("ACG-SHOP");
//session_start();
//session_write_close();
try {
    preg_match('/\/item\/(\d+)/', $_GET['s'] ?? "/", $_item);
    preg_match('/\/cat\/(\d+|recommend)/', $_GET['s'] ?? "/", $_cat);

    if (isset($_item[1]) && is_numeric($_item[1])) {
        $_GET['s'] = "/user/index/item";
        $_GET['mid'] = $_item[1];
    }

    if (isset($_cat[1]) && (is_numeric($_cat[1]) || $_cat[1] == "recommend")) {
        $_GET['s'] = "/user/index/index";
        $_GET['cid'] = $_cat[1];
    }

    //waf install -> 2025-07-26
    //?? 只兜住「参数不存在」，兜不住空值：nginx 重写首页时常给出 s= 或 s=/
    //（try_files $uri $uri/ /index.php?s=$uri 对 / 就是 s=/），这类合法的根路由
    //会被拼成控制器 App\Controller、方法名为空，class_exists 失败直接 404。
    $routePath = (string)($_GET['s'] ?? '');
    if (trim($routePath, "/ \t\n\r\0\x0B") === '') {
        $routePath = "/user/index/index";
    }
    $_GET['s'] = $routePath;
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
    Context::set(Base::LANGUAGE, \Kernel\Util\Lang::detect());

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
        Hook::inst()->load();
        hook(\App\Consts\Hook::KERNEL_INIT);
        AdminEntrance::guard();
    }

    //安全响应头
    if (!headers_sent()) {
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self'");
    }

    //记录日志
    RequestLogger::logCurrentRequest(Context::get(\Kernel\Context\Interface\Request::class));


    if (strtolower(trim((string)Context::get(Base::ROUTE), '/')) === '404.html') {
        try {
            $originUri = explode('?', (string)($_SERVER['REQUEST_URI'] ?? ''))[0];
            $notFoundUri = $originUri !== '' ? $originUri : '/404.html';
            hook(\App\Consts\Hook::HTTP_NOT_FOUND, $notFoundUri);
        } catch (Throwable $ignored) {
        }
        exit(feedback("404 Not Found", 200));
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
        $hasContentType = false;
        foreach (headers_list() as $responseHeader) {
            if (str_starts_with(strtolower($responseHeader), 'content-type:')) {
                $hasContentType = true;
                break;
            }
        }
        if (!$hasContentType) {
            header("Content-type: text/html; charset=utf-8");
        }
        echo $result;
    }
} catch (Throwable $e) {
    if ($e instanceof NotFoundException) {
        try {
            $notFoundRoute = (string)(Context::get(Base::ROUTE) ?? ($_GET['s'] ?? ''));
            hook(\App\Consts\Hook::HTTP_NOT_FOUND, $notFoundRoute);
        } catch (Throwable $ignored) {
        }
        exit(feedback("404 Not Found"));
    } elseif ($e instanceof \Kernel\Exception\ParameterMissException) {
        header('content-type:application/json;charset=utf-8');
        exit(json_encode(["code" => $e->getCode(), "msg" => lang($e->getMessage())], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } elseif ($e instanceof \Kernel\Exception\JSONException) {
        header('content-type:application/json;charset=utf-8');
        exit(json_encode(["code" => $e->getCode(), "msg" => lang($e->getMessage())], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } elseif ($e instanceof \Kernel\Exception\ViewException) {
        header("Content-type: text/html; charset=utf-8");
        exit(feedback($e->getFile() . "<br>" . $e->getMessage(), 500));
    } else {
        exit(feedback($e->getFile() . ":" . $e->getLine() . "<br>" . $e->getMessage(), 500));
    }
}
