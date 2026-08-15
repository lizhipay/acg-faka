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
        'shared_stock' => 'json'
    ];

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
            try {
                Ini::toArray((string)($var['config'] ?? ""));
            } catch (JSONException $e) {
                throw new JSONException("会员等级[{$groupId}]的独立配置解析失败：" . $e->getMessage());
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
}