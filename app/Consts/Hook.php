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
    const ADMIN_API_PLUGIN_SAVE_CONFIG = 0x15; //HOOK后台在保存配置时候，可以返回修改后的内容 HOOK时传参：string pluginName,array  postMap


}