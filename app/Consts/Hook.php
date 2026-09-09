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

    /* ───────────── 风控 / 人工审核 ─────────────
     *
     * 全部按引用传 `\App\Entity\RiskContext $risk`。订阅方**改对象、返回 null**；
     * 千万不要返回 bool —— 派发器遇到 bool 会短路整条链，后面的订阅方一个都不会跑。
     *
     * 核心在钩子返回后读 $risk->action：
     *   DENY   抛 JSONException($risk->message(兜底文案))
     *   REVIEW 走各场景自己的挂起分支（见各插入点注释）
     *   LIMIT  由订阅方自己给该身份挂软约束，核心不做特殊处理
     *
     * 注意：`hook()` 的变参是**按引用**接收的，实参必须是变量。
     * 传字面量、数组字面量或函数返回值会直接 500。
     */

    /** 注册：全部校验通过、$user 已装配但**尚未落库**。传参 RiskContext $risk, \App\Model\User $user */
    const USER_API_AUTH_REGISTER_VALIDATED = 0x2300;

    /** 找回密码：**验证码校验之前**（拒绝时不消耗掉用户的邮件/短信验证码）。传参 RiskContext $risk, string $account */
    const USER_API_AUTH_PASSWORD_BEGIN = 0x2301;

    /** 充值下单：金额与通道校验之后、下单之前。传参 RiskContext $risk, \App\Model\User $user, array $map */
    const USER_API_RECHARGE_TRADE_BEGIN = 0x2302;

    /** 提现申请：绑定校验通过、落库之前。传参 RiskContext $risk, \App\Model\User $user, array $map */
    const USER_API_CASH_SUBMIT_BEGIN = 0x2303;

    /** 工单创建：进入 Service 之前。传参 RiskContext $risk, mixed $user, array $map */
    const USER_API_TICKET_CREATE_BEGIN = 0x2304;

    /**
     * 发货之前（订单已支付，卡密尚未交出）。传参 RiskContext $risk, \App\Model\Order $order, \App\Model\Commodity $commodity
     *
     * 这是**唯一**能在卡密交出去之前把货扣下的位置，一处插入覆盖全部支付路径。
     * REVIEW 表示「钱照收、卡先不发」：delivery_status 留 0、secret 换成提示文案，
     * 也就是手动发货商品在付款到发货之间的形态。
     */
    const USER_API_ORDER_DELIVERY_BEGIN = 0x2305;

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

    /**
     * 收集插件需要放行的 CSP 外部源。
     *
     * 插件要加载第三方脚本、样式、字体、iframe 时用它声明域名，不必改内核、也不必
     * 往开源程序里写死任何域名：
     *
     *   #[Hook(point: \App\Consts\Hook::CSP_SOURCE_ALLOW)]
     *   public function CSP_SOURCE_ALLOW(array &$sources): void
     *   {
     *       $sources['script-src'][] = 'https://example.com';
     *   }
     *
     * 只有 script-src / style-src / font-src / img-src / connect-src / frame-src /
     * media-src / worker-src 可扩展；每一项必须是带主机名的源，'unsafe-inline'、裸 *、
     * 裸协议一律会被丢弃——否则一个插件就能把整条策略废掉。
     */
    public const CSP_SOURCE_ALLOW = 0x8102;

    /**
     * 商品分类即将被删除（在删除事务内、分类行落地删除之前广播）。
     *
     * 分类引用不一定是外键：插件常把 category_id 塞在自己的序列化配置里，核心的
     * 通用扫描看不见。以前的做法是**核心直接伸手去读某个插件的表**，还把插件名写死在
     * 后台提示里——公开版用户会看到一个自己根本没装的插件名（issue #918）。
     * 现在改成谁的引用谁自己清。
     *
     *   #[Hook(point: \App\Consts\Hook::CATEGORY_DELETE_BEFORE)]
     *   public function CATEGORY_DELETE_BEFORE(array $categoryIds): void
     *   {
     *       // 删掉/清空自己表里指向这些分类的行
     *   }
     *
     * **订阅方抛异常不会阻断删除**：核心会记日志并继续。站长要删分类，插件没有否决权。
     *
     * @param int[] $categoryIds 本次会被删除的全部分类 id（含未被显式选中的下级分类）
     */
    public const CATEGORY_DELETE_BEFORE = 0x8103;

    public const LANG_MISS = 0x9100;
}