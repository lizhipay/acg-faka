<?php
declare(strict_types=1);

namespace App\Consts;

/**
 * Interface Hook
 * @package App\Consts
 */
interface Hook
{
    //挂载点 app\View\Admin\Footer.html -> 放js的地方
    const ADMIN_VIEW_FOOTER = 0x1;
    //后台全局body
    const ADMIN_VIEW_BODY = 0x10201;
    //挂载点 app\View\Admin\Header.html -> 放css连接的地方
    const ADMIN_VIEW_HEADER = 0x2;
    //挂载点 app\View\Admin\Header.html -> 左边菜单栏
    const ADMIN_VIEW_MENU = 0x3;
    //挂载点 app\View\Admin\Header.html -> 应用商店旁边
    const ADMIN_VIEW_NAV = 0x4;

    //后台会员管理页面header
    const ADMIN_VIEW_USER_HEADER = 0x10002;
    //挂载点 app\View\Admin\User\User.html -> 底部代码，可以写一些JS逻辑
    const ADMIN_VIEW_USER_FOOTER = 0x9;
    //挂载点 app\View\Admin\User\User.html -> 按钮区域，可以加一些按钮
    const ADMIN_VIEW_USER_TOOLBAR = 0x10;
    //挂载点 app\View\Admin\User\User.html -> 数据表格
    const ADMIN_VIEW_USER_TABLE = 0x8;


    //挂载点 app\View\Admin\Trade\Commodity.html -> 数据表格
    const ADMIN_VIEW_COMMODITY_TABLE = 0x5;
    //挂载点 app\View\Admin\Trade\Commodity.html -> 底部代码，可以写一些JS逻辑
    const ADMIN_VIEW_COMMODITY_FOOTER = 0x6;
    //挂载点 app\View\Admin\Trade\Commodity.html -> 按钮区域，可以加一些按钮
    const ADMIN_VIEW_COMMODITY_TOOLBAR = 0x7;


    //后台分类页面的按钮
    const ADMIN_VIEW_CATEGORY_TOOLBAR = 0x701;
    //后台分类页面的横向表格
    const ADMIN_VIEW_CATEGORY_TABLE = 0x702;
    //后台分类页面提交的表格中
    const ADMIN_VIEW_CATEGORY_POST = 0x703;


    //挂载点 app\View\Admin\Trade\Order.html -> 数据表格
    const ADMIN_VIEW_ORDER_TABLE = 0x11;
    //挂载点 app\View\Admin\Trade\Order.html -> 底部代码，可以写一些JS逻辑
    const ADMIN_VIEW_ORDER_FOOTER = 0x12;
    //挂载点 app\View\Admin\Trade\Order.html -> 按钮区域，可以加一些按钮
    const ADMIN_VIEW_ORDER_TOOLBAR = 0x13;

    //挂载点 app\Controller\Admin\Config.php -> 挂载链接到网站设置的TOLLBAR上面，需要返回值二维数组
    const ADMIN_VIEW_CONFIG_TOOLBAR = 0x14;

    //-----------------------PLUGIN-----------------------------
    //HOOK后台在保存配置时候，可以返回修改后的内容 HOOK时传参：string pluginName,array  postMap
    const ADMIN_API_PLUGIN_SAVE_CONFIG = 0x15;


    //客户下单之前触发的点位，可以做一下防刷机制，HOOK时传参：array $_POST
    const USER_API_ORDER_TRADE_BEGIN = 0x16;
    //客户下单成功触发的点位，HOOK时传参：商品对象 $commodity, 订单对象 $order  支付对象 $pay
    const USER_API_ORDER_TRADE_AFTER = 0x17;
    //客户成功付款后触发的点位，HOOK时传参：商品对象 $commodity, 订单对象 $order 支付对象 $pay
    const USER_API_ORDER_PAY_AFTER = 0x18;
    //客户下单后，发起支付之前，HOOK时传参：商品对象 $commodity, 订单对象 $order 支付对象 $pay
    const USER_API_ORDER_TRADE_PAY_BEGIN = 0x171;


    //注册账号之前，可以做一些限制
    const USER_API_AUTH_REGISTER_BEGIN = 0x19;
    //注册账户之后，HOOK时传参：$user 注册成功后的用户对象
    const USER_API_AUTH_REGISTER_AFTER = 0x20;

    //登录账号之前，可以做一些限制
    const USER_API_AUTH_LOGIN_BEGIN = 0x21;
    //登录账户之后，HOOK时传参：$user 注册成功后的用户对象
    const USER_API_AUTH_LOGIN_AFTER = 0x22;


    //核心初始化完成
    const KERNEL_INIT = 0x30;

    //控制器被调用之前，传参：控制器名称，调用方法
    const CONTROLLER_CALL_BEFORE = 0x31;

    //控制器被调用之后，传参：控制器名称，调用方法，返回值
    const CONTROLLER_CALL_AFTER = 0X32;

    //渲染视图 ，传视图raw的指针地址
    const RENDER_VIEW = 0x33;

    //登录页面，第三方登录扩展按钮
    const USER_VIEW_AUTH_LOGIN_BUTTON = 0x41;
    //注册页面，第三方登录按钮扩展
    const USER_VIEW_AUTH_REGISTER_BUTTON = 0x42;
    //安全中心NAV SecurityNav.html
    const USER_VIEW_SECURITY_NAV = 0x43;
    //个人资料选项
    const USER_VIEW_PERSONAL_FORM = 0x44;

    //商品管理中的添加商品表单
    const ADMIN_VIEW_COMMODITY_POST = 0x45;

    //用户前台中的添加商品表单
    const USER_VIEW_COMMODITY_POST = 0x46;

    //在HTTP请求后，在返还给用户之前，拿到的返回数据
    const HTTP_ROUTE_RESPONSE = 0x47;

    //挂载点 app\View\User\* -> INDEX -> 头部
    const USER_VIEW_INDEX_HEADER = 0x10001;
    //挂载点 app\View\User\* -> INDEX -> 内容
    const USER_VIEW_INDEX_BODY = 0x10003;
    //挂载点 app\View\User\* -> INDEX -> 底部
    const USER_VIEW_INDEX_FOOTER = 0x10004;

    //前台获取的商品分类列表, 传入数组 array $data 指针
    const USER_API_INDEX_CATEGORY_LIST = 0x49;
    //前台获取商品列表，传入数组 array $data 指针
    const USER_API_INDEX_COMMODITY_LIST = 0x50;
    //前台获取商品详细信息 传入商品的数组 array $data 指针
    const USER_API_INDEX_COMMODITY_DETAIL_INFO = 0x51;
    //前台下单之前，计算完订单金额，传入计算的值 array $result 指针地址
    const USER_API_INDEX_TRADE_CALC_AMOUNT = 0x52;
    //前台获取完支付列表，传入 支付列表 array $list 指针地址
    const USER_API_INDEX_PAY_LIST = 0x53;

    //前台查询订单后，获取到的订单列表，传入列表数据指针地址 array $list
    const USER_API_INDEX_QUERY_LIST = 0x54;
    //前台查询订单里面，查询卡密信息触发，传入整个订单对象 Order $order
    const USER_API_INDEX_QUERY_SECRET = 0x55;
    //用户进到订单页面中，获取到订单列表，传入列表指针地址 array $list
    const USER_API_PURCHASE_RECORD_LIST = 0x56;


    // 挂载点：app\View\User\Theme\Cartoon\Common\Nav.html  左侧菜单
    const USER_VIEW_MENU = 0x57;


    //挂载点：app\View\User\Theme\Cartoon\Header.html 头部NAV，返回数组信息
    const USER_VIEW_HEADER_NAV = 0x88;

    //挂载点：订单查询里面的详细信息订单号后面的小尾巴
    const USER_VIEW_QUERY_TRADE_NO = 0x89;

    //挂载点 app\View\User\* -> Common -> 头部
    const USER_VIEW_HEADER = 0x128;
    //挂载点 app\View\User\* -> Common -> 内容
    const USER_VIEW_BODY = 0x129;
    //挂载点 app\View\User\* -> Common -> 底部
    const USER_VIEW_FOOTER = 0x130;

    //用户端全局  -> 头部
    const USER_GLOBAL_VIEW_HEADER = 0x228;
    //用户端全局 -> 内容
    const USER_GLOBAL_VIEW_BODY = 0x229;
    //用户端全局 -> 底部
    const USER_GLOBAL_VIEW_FOOTER = 0x230;

    //防火墙拦截 -> $message
    const WAF_INTERCEPT = 0x289;
}