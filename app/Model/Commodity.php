<?php
declare(strict_types=1);

namespace App\Model;


use App\Util\Ini;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kernel\Exception\JSONException;

/**
 * @property int $id
 * @property string $name
 * @property int $category_id
 * @property string $description
 * @property string $cover
 * @property float $factory_price
 * @property float $price
 * @property float $user_price
 * @property int $status
 * @property int $owner
 * @property string $create_time
 * @property int $integral
 * @property string $code
 * @property int $delivery_way
 * @property int $delivery_auto_mode
 * @property string $delivery_message
 * @property int $delivery_auto
 * @property int $contact_type
 * @property int $sort
 * @property int $password_status
 * @property int $coupon
 * @property int $shared_id
 * @property string $shared_code
 * @property float $shared_premium
 * @property int $shared_premium_type
 * @property int $shared_premium_template
 * @property int $seckill_status
 * @property int $api_status
 * @property int $draft_status
 * @property int $inventory_hidden
 * @property int $send_email
 * @property string $seckill_start_time
 * @property string $seckill_end_time
 * @property string $leave_message
 * @property int $only_user
 * @property int $purchase_count
 * @property string $widget
 * @property int $minimum
 * @property int $maximum
 * @property int $shared_sync
 * @property int $shared_amount_sync
 * @property int $shared_config_sync
 * @property int $inventory_sync
 * @property int $hide
 * @property array|string $config
 * @property array $shared_stock
 * @property float $draft_premium
 * @property int $stock
 * @property string $tags
 */
class Commodity extends Model
{
    /**
     * @var string
     */
    protected $table = 'commodity';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'factory_price' => 'float',
        'price' => 'float',
        'user_price' => 'float',
        'shared_premium' => 'float',
        'shared_premium_template' => 'integer',
        'status' => 'integer',
        'hide' => 'integer',
        'stock' => 'integer',
        'owner' => 'integer',
        'integral' => 'integer',
        'delivery_way' => 'integer',
        'delivery_auto_mode' => 'integer',
        'delivery_auto' => 'integer',
        'contact_type' => 'integer',
        'sort' => 'integer',
        'coupon' => 'integer',
        'shared_id' => 'integer',
        'seckill_status' => 'integer',
        'password_status' => 'integer',
        'category_id' => 'integer',
        'api_status' => 'integer',
        'draft_status' => 'integer',
        'draft_premium' => 'float',
        'inventory_hidden' => 'integer',
        'send_email' => 'integer',
        'only_user' => 'integer',
        'purchase_count' => 'integer',
        'minimum' => 'integer',
        'maximum' => 'integer',
        'shared_amount_sync' => 'integer',
        'shared_config_sync' => 'integer',
        'shared_sync' => 'integer',
        'substation_disable' => 'integer',
        'ban' => 'integer',
        'shared_stock' => 'json'
    ];

    /**
     * 3.5.9 存在一个回归窗口：后台保存商品时，自定义控件的 JSON 会以 URL 编码态
     * 入库（%5B%7B 开头），买家页控件渲染、下单校验、后台编辑弹窗全部失效。
     * 3.6.0 已修正保存链路；这里对读取做透明还原，让 3.5.9 期间保存过的存量数据
     * 立即恢复可用，站长无需手工修库——下次保存商品时即以明文回写。
     */
    public function getWidgetAttribute(?string $value): ?string
    {
        if (is_string($value) && preg_match('/^%(?:5B|7B)/i', $value)) {
            $decoded = urldecode($value);
            if (json_decode($decoded) !== null) {
                return $decoded;
            }
        }
        return $value;
    }

    public function owner(): ?HasOne
    {
        return $this->hasOne(User::class, "id", "owner");
    }

    public function shared(): ?HasOne
    {
        return $this->hasOne(Shared::class, "id", "shared_id");
    }

    public function category(): ?HasOne
    {
        return $this->hasOne(Category::class, "id", "category_id");
    }

    public function card(): ?HasMany
    {
        return $this->hasMany(Card::class, 'commodity_id', 'id');
    }

    public function order(): ?HasMany
    {
        return $this->hasMany(Order::class, 'commodity_id', 'id');
    }

    /**
     * 解析用户组配置
     * @param string|null $config
     * @param UserGroup|null $group
     * @return array|null
     * @throws JSONException
     */
    public static function parseGroupConfig(?string $config, ?UserGroup $group): ?array
    {
        if (!$group) {
            return null;
        }

        $levelPrice = (array)json_decode((string)$config, true);

        if (!array_key_exists($group->id, $levelPrice)) {
            return null;
        }

        $var = $levelPrice[$group->id];

        //解析自定义金额
        $parse = [];
        $parse['amount'] = (float)$var['amount'];
        $parse['config'] = Ini::toArray((string)$var['config']);
        $parse['show'] = (int)$var['show'];
        return $parse;
    }

    /**
     * 「付款即发货」：手动发货商品付款后直接把发货信息作为卡密发出，订单即为已发货。
     * 后台与商户端保存共用。只在本次提交动到发货方式 / 开关 / 发货信息时校验最终状态，
     * 表格里只提交 id + 单个字段的快捷开关不受影响。
     * @param array $map 待保存字段（会把 delivery_auto 归一成 0/1）
     * @param Commodity|null $current 修改时的原商品
     * @throws JSONException
     */
    public static function assertDeliveryAuto(array &$map, ?self $current): void
    {
        if (array_key_exists('delivery_auto', $map)) {
            $map['delivery_auto'] = (int)$map['delivery_auto'] === 1 ? 1 : 0;
        }
        //delivery_message 是 varchar(255)：超长时严格模式直接报错，非严格模式会被悄悄截断——发出去的下载链接就残了
        if (array_key_exists('delivery_message', $map) && mb_strlen((string)$map['delivery_message']) > 255) {
            throw new JSONException("发货信息最多255个字");
        }
        if (!array_key_exists('delivery_way', $map) && !array_key_exists('delivery_auto', $map) && !array_key_exists('delivery_message', $map)) {
            return;
        }
        $way = (int)($map['delivery_way'] ?? $current?->delivery_way ?? 0);
        $auto = (int)($map['delivery_auto'] ?? $current?->delivery_auto ?? 0);
        $message = (string)($map['delivery_message'] ?? $current?->delivery_message ?? '');
        if ($way === 1 && $auto === 1 && trim($message) === '') {
            throw new JSONException("开启了付款即发货，请填写发货信息");
        }
    }

    /**
     * 校验会员等级独立配置(level_price)的数据格式，非法时抛出异常。
     * 该字段运行时会经 parseGroupConfig -> Ini::toArray 解析，脏数据一旦入库，
     * 登录用户的商品列表和详情会整体报错，所以必须在保存入口拦截。
     * @param string|null $levelPrice
     * @throws JSONException
     */
    public static function validateLevelPrice(?string $levelPrice): void
    {
        if ($levelPrice === null || trim($levelPrice) === "") {
            return;
        }

        $list = json_decode($levelPrice, true);

        if (!is_array($list)) {
            throw new JSONException("会员等级配置不是有效的JSON数据");
        }

        foreach ($list as $groupId => $var) {
            if (!is_array($var)) {
                throw new JSONException("会员等级[{$groupId}]的配置格式错误");
            }
            if (isset($var['amount']) && trim((string)$var['amount']) !== '' && !preg_match('/^\d+(\.\d{1,2})?$/', trim((string)$var['amount']))) {
                throw new JSONException("会员等级[{$groupId}]的价格必须是不小于0且最多两位小数的数字");
            }
            try {
                $parsed = Ini::toArray((string)($var['config'] ?? ""));
            } catch (JSONException $e) {
                throw new JSONException("会员等级[{$groupId}]的独立配置解析失败：" . $e->getMessage());
            }
            //level_price 的内层 config 会经 parseGroupConfig() 合并成有效配置参与估价，是与顶层 config
            //同源的负价通道。此前只查语法不查价格：商户在这里填负价→买家估价变负→amount<=0 免支付直发。
            //与顶层 config 同口径校验各价格档非负。
            self::assertConfigPricesNonNegative($parsed);
        }
    }

    /**
     * 校验（已解析的）商品配置里各价格档为「不小于 0 的数字」。
     *
     * category/wholesale/category_wholesale 的值是成交单价、sku 的值是溢价，任一为负或非数字都可能算出
     * 负数金额→trade() 命中 amount<=0 免支付直发（平台货源商品还会让平台向上游代付=亏损）。空值放行
     * （下游按 0 处理）。顶层 config 与 level_price 内层 config 两条路径共用本校验，避免任一处遗漏。
     *
     * @param array $config Ini::toArray() 解析后的配置
     * @throws JSONException
     */
    public static function assertConfigPricesNonNegative(array $config): void
    {
        foreach (['category', 'wholesale', 'category_wholesale', 'sku'] as $section) {
            if (!empty($config[$section]) && is_array($config[$section])) {
                array_walk_recursive($config[$section], static function ($value): void {
                    if ($value === '' || $value === null) {
                        return;
                    }
                    if (!is_numeric($value) || (float)$value < 0) {
                        throw new JSONException("商品价格配置必须是不小于0的数字哦(｡￫‿￩｡)");
                    }
                    if (!preg_match('/^\d+(\.\d{1,2})?$/', trim((string)$value))) {
                        throw new JSONException("商品价格配置最多两位小数");
                    }
                });
            }
        }
    }

    /**
     * @param string $config
     * @param int $type
     * @param float $premium
     * @return string
     * @throws JSONException
     */
    public static function premiumConfig(string $config, int $type, float $premium): string
    {
        $configs = Ini::toArray($config);

        if (array_key_exists("category", $configs)) {
            foreach ($configs['category'] as $ck => $cv) {
                //计算当前种类的成本
                $price = $type == 0 ? (float)$cv + $premium : (float)$cv + ($premium * (float)$cv);
                $price = (int)($price * 100) / 100;
                $configs['category'][$ck] = $price;
            }
        }
        return Ini::toConfig($configs);
    }

    /**
     * 商品标签的可选颜色。
     *
     * 只认这张表里的 token，不接受任意 CSS —— 标签会被渲染进 6 套主题的商品卡片，
     * 放开颜色等于给管理员一个往前台注入样式的入口。
     * 具体色值由各主题自己定义 .commodity-tag--<token>，深浅色主题各自适配。
     */
    public const TAG_COLORS = ['red', 'orange', 'green', 'cyan', 'blue', 'purple', 'pink', 'gray'];

    /** 单个商品最多几个标签，多了会把卡片撑乱 */
    public const TAG_MAX = 5;
    /** 单个标签最多几个字 */
    public const TAG_TEXT_MAX = 10;

    /**
     * 把后台表单提交的标签归一化成入库 JSON。
     *
     * 后台用的是表单组件的 attribute 类型，提交上来是 [{name: 文字, value: 颜色}]，
     * 这里统一成 [{text, color}]，顺带做数量、长度、颜色白名单的裁剪。
     *
     * @param mixed $raw 表单原值（JSON 字符串或数组）
     */
    public static function normalizeTags(mixed $raw): string
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            //解不出来就返回 ''，Save::setMap 会跳过 -> 保持原值不动。
            //这是对垃圾输入的正确反应：宁可不改，也不要把好数据清掉。
            return '';
        }

        $tags = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            // 兼容两种键名：表单来的是 name/value，接口直传的是 text/color
            $text = trim((string)($item['text'] ?? $item['name'] ?? ''));
            if ($text === '') {
                continue;
            }
            $text = mb_substr($text, 0, self::TAG_TEXT_MAX);

            $color = strtolower(trim((string)($item['color'] ?? $item['value'] ?? '')));
            if (!in_array($color, self::TAG_COLORS, true)) {
                $color = self::TAG_COLORS[0];
            }

            $tags[] = ['text' => $text, 'color' => $color];
            if (count($tags) >= self::TAG_MAX) {
                break;
            }
        }

        // !! 没有标签时必须返回 '[]' 而不是 '' !!
        // Save::setMap 把空字符串当成「本次不修改这个字段」直接跳过，
        // 返回 '' 的话「把标签全删掉」这个操作就永远存不进去。
        return (string)json_encode($tags, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 读出标签给前台用。脏数据一律当成没有标签，单个商品的坏数据不能拖垮整个列表。
     *
     * @return array<int, array{text: string, color: string}>
     */
    public static function parseTags(mixed $stored): array
    {
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }
        if (!is_array($stored)) {
            return [];
        }

        $out = [];
        foreach ($stored as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text = trim((string)($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $color = (string)($item['color'] ?? '');
            $out[] = [
                'text' => mb_substr($text, 0, self::TAG_TEXT_MAX),
                'color' => in_array($color, self::TAG_COLORS, true) ? $color : self::TAG_COLORS[0],
            ];
            if (count($out) >= self::TAG_MAX) {
                break;
            }
        }
        return $out;
    }

    /**
     * 秒杀是否正在进行中。
     *
     * 注意「开关打开」不等于「正在秒杀」——还没开始或已经结束都不算，
     * 所以列表不能直接把 seckill_status 丢给前端当标签用。见 issue #806
     *
     * 下单流程（Order::valuation / trade）里有同样的时间判定，但它要区分
     * 「还未开始」和「已结束」给出不同提示，语义比这里的布尔值更细，
     * 故意没有合并——那条链路是支付前的校验，不宜为了展示功能去动。
     *
     * @param int|null $now 便于测试，默认取当前时间
     * @return bool
     */
    public function isSeckillActive(?int $now = null): bool
    {
        if ((int)$this->seckill_status !== 1) {
            return false;
        }
        $now ??= time();
        $start = strtotime((string)$this->seckill_start_time);
        $end = strtotime((string)$this->seckill_end_time);
        if ($start !== false && $now < $start) {
            return false;
        }
        if ($end !== false && $now > $end) {
            return false;
        }
        return true;
    }

    /**
     * 商品是否配置了批发价（含按种类的批发价阶梯）。
     *
     * 批发价存在商品 config 的 [wholesale] / [category_wholesale] 段里。
     * 这里只回答"有没有"，不把 config 本身吐给前端——那里面还有成本价、
     * 种类单价、SKU 加价等不该外泄的定价结构。见 issue #806
     *
     * @param string|null $config 商品的 Ini 配置文本
     * @return bool
     */
    public static function hasWholesaleConfig(?string $config): bool
    {
        $config = (string)$config;
        if (trim($config) === '') {
            return false;
        }

        try {
            $parsed = \App\Util\Ini::toArray($config);
        } catch (\Throwable $e) {
            return false; //配置脏数据不该让整个列表报错，当作没配批发价
        }

        if (!empty($parsed['wholesale']) && is_array($parsed['wholesale'])) {
            return true;
        }

        //种类批发价是两层结构：race -> 数量档位
        if (!empty($parsed['category_wholesale']) && is_array($parsed['category_wholesale'])) {
            foreach ($parsed['category_wholesale'] as $ladder) {
                if (is_array($ladder) && $ladder !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 会员价：留空 / 为 0 时回退到零售价。
     *
     * 登录用户一律按会员价计价（会员等级门槛从 0 起，人人都有等级），所以会员价忘填
     * 就是 0；而订单金额 ≤0 会被判定为免费并立即发货——一个空字段就等于把商品白送，
     * 且站长以游客身份自测时价格正常，很难察觉。这里统一回退到零售价，
     * 下单计价与前台展示都走它，保证两边口径一致。
     *
     * 商品本来就是免费的（零售价也是 0）时回退结果仍为 0，不影响真正的免费商品。
     */
    public function memberPrice(): float
    {
        $userPrice = (float)$this->user_price;

        return $userPrice > 0 ? $userPrice : (float)$this->price;
    }
}