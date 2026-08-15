<?php
declare(strict_types=1);

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * 加价模板：把「基准价 + 加价规则」保存下来，一键套用到大批商品，
 * 免去逐个商品手填游客价/会员价/每个会员等级的价格（issue #798）。
 *
 * @property int $id
 * @property string $name
 * @property int $base 0=成本价 1=当前售价
 * @property int $guest_type 0=固定金额 1=百分比
 * @property float $guest_value
 * @property int $user_type
 * @property float $user_value
 * @property string $level_config {等级id:{type,value}}
 * @property int $rounding 0=不取整 1=四舍五入 2=向上取整
 * @property string $create_time
 */
class PriceTemplate extends Model
{
    /** 加价基准 */
    public const BASE_FACTORY = 0; //成本价
    public const BASE_PRICE = 1;   //当前售价

    /** 加价方式 */
    public const TYPE_FIXED = 0;   //固定金额
    public const TYPE_PERCENT = 1; //百分比

    /**
     * commodity.shared_premium_type 取此值时（0=固定金额 1=百分比），
     * 该接入商品的加价规则整套来自 shared_premium_template 指向的模板
     */
    public const SHARED_PREMIUM_TYPE = 2;

    /** 取整方式，与分站加价的 UserCommodity::ROUNDING_* 保持一致 */
    public const ROUNDING_NONE = 0;
    public const ROUNDING_ROUND = 1;
    public const ROUNDING_CEIL = 2;

    /**
     * @var string
     */
    protected $table = "price_template";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'base' => 'integer',
        'guest_type' => 'integer',
        'guest_value' => 'float',
        'user_type' => 'integer',
        'user_value' => 'float',
        'rounding' => 'integer',
    ];

    /**
     * 按规则算出加价后的价格。
     *
     * @param float $basePrice 基准价
     * @param int $type 加价方式
     * @param float $value 加价值（百分比时按 30 表示 +30%）
     * @return string 两位小数金额字符串
     */
    public static function apply(float $basePrice, int $type, float $value): string
    {
        $base = new \Kernel\Util\Decimal(sprintf('%.2f', $basePrice), 2);
        $result = $type === self::TYPE_PERCENT
            ? $base->add($base->mul(sprintf('%.4f', $value / 100))->getAmount())
            : $base->add(sprintf('%.2f', $value));
        $amount = $result->getAmount();

        //加价可以是负数（做折扣），但价格不能为负
        return (float)$amount < 0 ? '0.00' : $amount;
    }

    /**
     * 取整（与分站加价取整口径一致）
     * @param string $amount
     * @param int $rounding
     * @return string
     */
    public static function round(string $amount, int $rounding): string
    {
        return match ($rounding) {
            self::ROUNDING_ROUND => sprintf('%.2f', round((float)$amount)),
            self::ROUNDING_CEIL => sprintf('%.2f', ceil((float)$amount)),
            default => $amount,
        };
    }

    /**
     * 给「配置参数」里的价格整体套用同一条加价规则。
     *
     * config 里有两类数值，语义不同，处理方式必须区分：
     *   - 绝对价：category（种类单价）、wholesale（批发价）、category_wholesale（种类批发价）
     *     —— 和商品单价一样，按完整规则加价。
     *   - 加价额：sku（选中该 SKU 时在单价上additional 加的钱）
     *     —— 百分比模式按同比例放大（整体涨 30%，SKU 加价也涨 30%，相对比重不变）；
     *        固定金额模式保持原样，否则「每个 SKU 都再 +3 元」会重复加价。
     *
     * 基准=成本价时，种类价会优先以 [category_factory]（对接插件同步下来的种类成本价）
     * 为基准计算，这正是「对接商品上游调价后一键重算」的关键；category_factory 本身
     * 是成本，任何时候都不加价。
     *
     * @param string $config Ini 文本
     * @param int $type
     * @param float $value
     * @param int $rounding
     * @param bool $useFactoryBase 是否以成本价为基准
     * @return string 处理后的 Ini 文本；解析失败时原样返回，绝不破坏站长已有配置
     */
    public static function applyToConfig(string $config, int $type, float $value, int $rounding, bool $useFactoryBase = false): string
    {
        if (trim($config) === '') {
            return $config;
        }

        try {
            $parsed = \App\Util\Ini::toArray($config);
        } catch (\Throwable $e) {
            return $config; //配置本身有语法问题，交给商品编辑页去报错，这里不动它
        }

        $priced = static fn($amount): string => self::round(self::apply((float)$amount, $type, $value), $rounding);

        //绝对价：单层。种类价在「成本价基准」下改用 category_factory 作为起点
        $factory = ($useFactoryBase && isset($parsed['category_factory']) && is_array($parsed['category_factory']))
            ? $parsed['category_factory']
            : [];

        foreach (['category', 'wholesale'] as $section) {
            if (!isset($parsed[$section]) || !is_array($parsed[$section])) {
                continue;
            }
            foreach ($parsed[$section] as $key => $amount) {
                $source = ($section === 'category' && isset($factory[$key]) && is_numeric($factory[$key]))
                    ? $factory[$key]
                    : $amount;
                if (is_numeric($source)) {
                    $parsed[$section][$key] = $priced($source);
                }
            }
        }

        //绝对价：两层（种类 -> 数量档位）
        if (isset($parsed['category_wholesale']) && is_array($parsed['category_wholesale'])) {
            foreach ($parsed['category_wholesale'] as $race => $ladder) {
                if (!is_array($ladder)) {
                    continue;
                }
                foreach ($ladder as $num => $amount) {
                    if (is_numeric($amount)) {
                        $parsed['category_wholesale'][$race][$num] = $priced($amount);
                    }
                }
            }
        }

        //加价额：两层（属性 -> 选项），仅百分比模式按比例缩放
        if ($type === self::TYPE_PERCENT && isset($parsed['sku']) && is_array($parsed['sku'])) {
            foreach ($parsed['sku'] as $group => $options) {
                if (!is_array($options)) {
                    continue;
                }
                foreach ($options as $option => $amount) {
                    if (is_numeric($amount) && (float)$amount > 0) {
                        $parsed['sku'][$group][$option] = self::round(self::apply((float)$amount, $type, $value), $rounding);
                    }
                }
            }
        }

        try {
            return \App\Util\Ini::toConfig($parsed);
        } catch (\Throwable $e) {
            return $config;
        }
    }

    /**
     * 把算出的等级价合并进商品原有的 level_price。
     * 保留「绝对显示」等其他字段；该等级若有自己的配置参数（种类价/批发价/SKU），
     * 也按同一条等级规则一起加价，否则等级价改了、等级的种类价还是老的。
     *
     * @param string $original
     * @param array<int, array{amount:string, rule:array{type:int,value:float}}> $levels
     * @param int $rounding
     * @param bool $useFactoryBase
     * @return string
     */
    public static function mergeLevelPrice(string $original, array $levels, int $rounding, bool $useFactoryBase): string
    {
        if ($levels === []) {
            return $original;
        }

        $config = json_decode($original, true);
        if (!is_array($config)) {
            $config = [];
        }

        foreach ($levels as $groupId => $computed) {
            $existing = is_array($config[$groupId] ?? null) ? $config[$groupId] : [];
            $existing['amount'] = (float)$computed['amount'];
            if (isset($existing['config']) && is_string($existing['config']) && trim($existing['config']) !== '') {
                $existing['config'] = self::applyToConfig(
                    $existing['config'],
                    $computed['rule']['type'],
                    $computed['rule']['value'],
                    $rounding,
                    $useFactoryBase
                );
            }
            $config[$groupId] = $existing;
        }

        return (string)json_encode($config, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 店铺共享「接入货源」按模板加价。
     *
     * 与后台「应用模板」有两点刻意的不同，都是这个场景本身决定的：
     *   1. 基准恒为远端游客价。本地商品此时压根没有成本价（接入的商品 factory_price 恒为 0），
     *      远端报给你的价就是你的进货成本，所以模板的「加价基准」在这里不参与判断。
     *   2. 种类价一律以远端 [category] 为基准，绝不去读远端的 [category_factory]——
     *      那是上游自己的成本，拿它当基准等于平进平出。
     * 三档价（游客/会员/各等级）都从同一个基准算起，模板填的是多少，出来就是多少，
     * 不受上游内部定价结构影响。
     *
     * @param string $config 远端配置参数（Ini 文本）
     * @param string $price 远端游客价
     * @param string $userPrice 远端会员价
     * @param string $levelPrice 本地商品已有的 level_price（新入库时为空）
     * @return array{price:string, user_price:string, config:string, level_price:string}
     */
    public function forShared(string $config, string $price, string $userPrice, string $levelPrice = ''): array
    {
        $base = (float)$price;
        $round = fn(string $amount): string => self::round($amount, $this->rounding);

        //纯种类商品（商品单价为 0，价格全在 [category] 里）没有商品级基准，
        //三档价保持远端原值，只让配置参数里的种类价参与加价——与后台应用模板口径一致
        $levels = [];
        if ($base > 0) {
            foreach ($this->levelRules() as $groupId => $rule) {
                $levels[$groupId] = [
                    'amount' => $round(self::apply($base, $rule['type'], $rule['value'])),
                    'rule' => $rule,
                ];
            }
        }

        return [
            'price' => $base > 0
                ? $round(self::apply($base, $this->guest_type, (float)$this->guest_value))
                : sprintf('%.2f', $base),
            'user_price' => $base > 0
                ? $round(self::apply($base, $this->user_type, (float)$this->user_value))
                : sprintf('%.2f', (float)$userPrice),
            'config' => self::applyToConfig($config, $this->guest_type, (float)$this->guest_value, $this->rounding, false),
            'level_price' => self::mergeLevelPrice($levelPrice, $levels, $this->rounding, false),
        ];
    }

    /**
     * 溢价类金额（如指定卡密购买溢价）按模板放大。
     *
     * 口径与 applyToConfig 处理 [sku] 一致：百分比模式同比例放大，保持它在总价里的比重；
     * 固定金额模式原样返回，否则「每笔再 +N 元」会和商品单价上的加价重复收一次。
     *
     * @param float $amount
     * @return string
     */
    public function markupExtra(float $amount): string
    {
        if ($amount <= 0) {
            return '0.00';
        }

        return $this->guest_type === self::TYPE_PERCENT
            ? self::round(self::apply($amount, self::TYPE_PERCENT, (float)$this->guest_value), $this->rounding)
            : sprintf('%.2f', $amount);
    }

    /**
     * 各会员等级的加价规则
     * @return array<int, array{type:int, value:float}>
     */
    public function levelRules(): array
    {
        $raw = json_decode((string)$this->level_config, true);
        if (!is_array($raw)) {
            return [];
        }

        $rules = [];
        foreach ($raw as $groupId => $rule) {
            if (!is_array($rule) || !isset($rule['value']) || !is_numeric($rule['value'])) {
                continue;
            }
            $groupId = (int)$groupId;
            if ($groupId <= 0) {
                continue;
            }
            $rules[$groupId] = [
                'type' => (int)($rule['type'] ?? self::TYPE_PERCENT) === self::TYPE_FIXED
                    ? self::TYPE_FIXED
                    : self::TYPE_PERCENT,
                'value' => (float)$rule['value'],
            ];
        }
        return $rules;
    }
}
