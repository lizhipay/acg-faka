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
    //挂载点 app\View\Admin\Header.html -> 放css连接的地方
    const ADMIN_VIEW_HEADER = 0x2;
    //挂载点 app\View\Admin\Header.html -> 左边菜单栏
    const ADMIN_VIEW_MENU = 0x3;
    //挂载点 app\View\Admin\Header.html -> 应用商店旁边
    const ADMIN_VIEW_NAV = 0x4;

    //挂载点 app\View\Admin\Trade\Commodity.html -> 数据表格
    const ADMIN_VIEW_COMMODITY_TABLE = 0x5;
    //挂载点 app\View\Admin\Trade\Commodity.html -> 底部代码，可以写一些JS逻辑
    const ADMIN_VIEW_COMMODITY_FOOTER = 0x6;
    //挂载点 app\View\Admin\Trade\Commodity.html -> 按钮区域，可以加一些按钮
    const ADMIN_VIEW_COMMODITY_TOOLBAR = 0x7;

    //挂载点 app\View\Admin\User\User.html -> 数据表格
    const ADMIN_VIEW_USER_TABLE = 0x8;
    //挂载点 app\View\Admin\User\User.html -> 底部代码，可以写一些JS逻辑
    const ADMIN_VIEW_USER_FOOTER = 0x9;
    //挂载点 app\View\Admin\User\User.html -> 按钮区域，可以加一些按钮
    const ADMIN_VIEW_USER_TOOLBAR = 0x10;

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


    //注册账号之前，可以做一些限制
    const USER_API_AUTH_REGISTER_BEGIN = 0x19;
    //注册账户之后，HOOK时传参：$user 注册成功后的用户对象
    const USER_API_AUTH_REGISTER_AFTER = 0x20;

    //登录账号之前，可以做一些限制
    const USER_API_AUTH_LOGIN_BEGIN = 0x21;
    //登录账户之后，HOOK时传参：$user 注册成功后的用户对象
    const USER_API_AUTH_LOGIN_AFTER = 0x22;



    //-----------------------------MY

    //挂载点 app\View\User\* -> INDEX -> 头部
    const USER_VIEW_INDEX_HEADER = 0x10001;
    //挂载点 app\View\User\* -> INDEX -> 内容
    const USER_VIEW_INDEX_BODY = 0x10003;
    //挂载点 app\View\User\* -> INDEX -> 底部
    const USER_VIEW_INDEX_FOOTER = 0x10004;

}