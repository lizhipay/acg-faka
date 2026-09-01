<?php
declare(strict_types=1);

namespace App\Consts;

interface Hook
{
    const ADMIN_VIEW_FOOTER = 0x1;

    const ADMIN_VIEW_BODY = 0x10201;

    const ADMIN_VIEW_HEADER = 0x2;

    const ADMIN_VIEW_MENU = 0x3;

    const ADMIN_VIEW_NAV = 0x4;

    const ADMIN_VIEW_USER_HEADER = 0x10002;

    const ADMIN_VIEW_USER_FOOTER = 0x9;

    const ADMIN_VIEW_USER_TOOLBAR = 0x10;

    const ADMIN_VIEW_USER_TABLE = 0x8;

    const ADMIN_VIEW_COMMODITY_TABLE = 0x5;

    const ADMIN_VIEW_COMMODITY_FOOTER = 0x6;

    const ADMIN_VIEW_COMMODITY_TOOLBAR = 0x7;

    const ADMIN_VIEW_CATEGORY_TOOLBAR = 0x701;

    const ADMIN_VIEW_CATEGORY_TABLE = 0x702;

    const ADMIN_VIEW_CATEGORY_POST = 0x703;

    const ADMIN_VIEW_ORDER_TABLE = 0x11;

    const ADMIN_VIEW_ORDER_FOOTER = 0x12;

    const ADMIN_VIEW_ORDER_TOOLBAR = 0x13;

    const ADMIN_VIEW_CARD_TOOLBAR = 0x801;
    const ADMIN_VIEW_CARD_FOOTER = 0x802;

    const ADMIN_VIEW_CONFIG_TOOLBAR = 0x14;

    const ADMIN_API_PLUGIN_SAVE_CONFIG = 0x15;

    const USER_API_ORDER_TRADE_BEGIN = 0x16;

    const USER_API_ORDER_TRADE_AFTER = 0x17;

    const USER_API_ORDER_PAY_AFTER = 0x18;

    const USER_API_ORDER_TRADE_PAY_BEGIN = 0x171;

    const USER_API_RECHARGE_AFTER = 0x18191;

    const USER_API_AUTH_REGISTER_BEGIN = 0x19;

    const USER_API_AUTH_REGISTER_AFTER = 0x20;

    const USER_API_AUTH_LOGIN_BEGIN = 0x21;

    const USER_API_AUTH_LOGIN_AFTER = 0x22;

    const USER_API_AUTH_LOGIN_FAIL = 0x23;

    const ADMIN_API_AUTH_LOGIN_AFTER = 0x61;

    const ADMIN_API_AUTH_LOGIN_FAIL = 0x62;

    const KERNEL_INIT = 0x30;

    const CONTROLLER_CALL_BEFORE = 0x31;

    const CONTROLLER_CALL_AFTER = 0X32;

    const RENDER_VIEW = 0x33;

    const USER_VIEW_AUTH_LOGIN_BUTTON = 0x41;

    const USER_VIEW_AUTH_REGISTER_BUTTON = 0x42;

    const ADMIN_VIEW_AUTH_LOGIN_FORM = 0x60;

    const USER_VIEW_SECURITY_NAV = 0x43;

    const USER_VIEW_PERSONAL_FORM = 0x44;

    const ADMIN_VIEW_COMMODITY_POST = 0x45;

    const USER_VIEW_COMMODITY_POST = 0x46;

    const HTTP_ROUTE_RESPONSE = 0x47;

    const HTTP_NOT_FOUND = 0x48;

    const USER_VIEW_INDEX_HEADER = 0x10001;

    const USER_VIEW_INDEX_BODY = 0x10003;

    const USER_VIEW_INDEX_FOOTER = 0x10004;

    const USER_API_INDEX_CATEGORY_LIST = 0x49;

    const USER_API_INDEX_COMMODITY_LIST = 0x50;

    const USER_API_INDEX_COMMODITY_DETAIL_INFO = 0x51;

    const USER_API_INDEX_TRADE_CALC_AMOUNT = 0x52;

    const USER_API_INDEX_PAY_LIST = 0x53;

    const USER_API_INDEX_QUERY_LIST = 0x54;

    const USER_API_INDEX_QUERY_SECRET = 0x55;

    const USER_API_PURCHASE_RECORD_LIST = 0x56;

    const USER_VIEW_MENU = 0x57;

    const USER_VIEW_HEADER_NAV = 0x88;

    const USER_VIEW_QUERY_TRADE_NO = 0x89;

    const USER_VIEW_HEADER = 0x128;

    const USER_VIEW_BODY = 0x129;

    const USER_VIEW_FOOTER = 0x130;

    const USER_GLOBAL_VIEW_HEADER = 0x228;

    const USER_GLOBAL_VIEW_BODY = 0x229;

    const USER_GLOBAL_VIEW_FOOTER = 0x230;

    const WAF_INTERCEPT = 0x289;

    const SERVICE_SMTP_SEND_BEFORE = 0x3000;

    const SERVICE_SMTP_SEND_SUCCESS = 0x3001;

    const SERVICE_SMTP_SEND_ERROR = 0x3002;

    const SERVICE_PAY_CALLBACK_FAIL = 0x3010;

    const USER_API_TICKET_CREATE_AFTER = 0x2100;

    const USER_API_TICKET_REPLY_AFTER = 0x2101;

    const ADMIN_API_TICKET_REPLY_AFTER = 0x2102;

    const ORDER_MANUAL_DELIVERY_AFTER = 0x2200;

    public const HACK_ROUTE_TABLE_COLUMNS = 0x2005;
    public const HACK_ROUTE_TABLE_SEARCH = 0x2006;
    public const HACK_SUBMIT_FORM = 0x9038;
    public const HACK_SUBMIT_TAB = 0x9039;

    public const SERVICE_SHOP_GET_ITEM_STOCK = 0x8000;

    /**
     * 商品发生变更（新增/修改/删除/上下架/批量设置/对接同步）。
     *
     * 核心此前没有任何商品写侧钩子，而商品的批量启停、批量设置、批量删除走的都是
     * 查询构造器的 update()/delete()，Eloquent 模型事件不会触发，插件无从感知。
     * 这里统一在「事务提交之后」广播受影响的商品 id，让订阅方自己去算差量。
     *
     * @param int[]      $ids     受影响的商品 id
     * @param string     $action  create|update|delete|status|batch|sync
     * @param mixed|null $before  单个商品修改前的模型（仅 save 路径提供，其余为 null）
     */
    public const COMMODITY_CHANGE_AFTER = 0x8100;

    /**
     * 卡密池发生变更，即自动发货商品的库存变了。
     *
     * 同样在事务提交之后广播，参数给的是「受影响的商品 id」而不是卡密 id ——
     * 订阅方关心的是哪个商品的库存动了，卡密本身是实现细节。
     *
     * @param int[]  $commodityIds 受影响的商品 id
     * @param string $reason       import|edit|lock|unlock|sell|delete|order
     */
    public const CARD_CHANGE_AFTER = 0x8101;

    public const LANG_MISS = 0x9100;
}