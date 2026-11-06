<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;
use Kernel\Util\Decimal;

/**
 * @property int $id
 * @property int $user_id
 * @property int $commodity_id
 * @property int $status
 * @property string $premium
 * @property int $rounding
 * @property string $name
 * @property string $description
 */
class UserCommodity extends Model
{
    /**
     * 价格取整方式（issue #792：百分比加价后价格出现小数）
     */
    public const ROUNDING_NONE = 0;  //不取整
    public const ROUNDING_ROUND = 1; //四舍五入到整元
    public const ROUNDING_CEIL = 2;  //向上取整到整元

    /**
     * @var string
     */
    protected $table = "user_commodity";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer', 'user_id' => 'integer', 'commodity_id' => 'integer', 'status' => 'integer', 'premium' => 'integer', 'rounding' => 'integer'];

    /**
     * 对"加价后"的金额应用取整规则。入参出参都是两位小数的金额字符串，
     * 金额远小于 float 精度上限，round/ceil 安全。
     * @param string $amount
     * @return string
     */
    public function applyRounding(string $amount): string
    {
        return match ((int)$this->rounding) {
            self::ROUNDING_ROUND => sprintf("%.2f", round((float)$amount)),
            self::ROUNDING_CEIL => sprintf("%.2f", ceil((float)$amount)),
            default => $amount,
        };
    }

    /**
     * 按本行的加价率对「平台基准价」加价并取整，返回两位小数金额字符串。
     *
     * 加价只增不减：结果**永不低于基准价**。ROUNDING_ROUND 是「四舍五入到整元」，会把
     * 0.1~0.4 元抹掉——对单价 < 0.5 元的商品直接抹成 0.00，命中下单侧 amount<=0 的免支付
     * 直发闸门 → 分站上任何人 0 元把真实卡密（含货源商品，平台还要向上游代付）搬走。这里把
     * 取整结果钳回 >= 基准价，堵死这条「四舍五入归零」的免费拿卡路径，同时保留对 >=0.5 元
     * 商品向上取整到整元的原有体验。
     *
     * @param string|int|float $base 平台基准价（金额，两位小数语义）
     * @return string
     */
    public function markup(string|int|float $base): string
    {
        $base = (new Decimal((string)$base))->getAmount();

        if ((int)$this->premium <= 0) {
            return $base;
        }

        $marked = (new Decimal($base))->mul($this->premium / 100)->add($base)->getAmount();
        $rounded = $this->applyRounding($marked);

        return bccomp($rounded, $base, 2) < 0 ? $base : $rounded;
    }


    public const SOURCE_COMMODITY = 'commodity';
    public const SOURCE_CATEGORY = 'category';
    public const SOURCE_SHOP = 'shop';

    /**
     * 分站的加价规则（#975）：全店默认 + 各分类规则，一次查齐。列表页先取一次再逐商品 pick()。
     * @return array{shop: array{premium:int, rounding:int}|null, category: array<int, array{premium:int, rounding:int}>}
     */
    public static function rulesOf(Business $business): array
    {
        \App\Util\Schema::ensureSubstationPremiumRules();
        $shop = (int)$business->premium > 0
            ? ['premium' => (int)$business->premium, 'rounding' => (int)$business->premium_rounding]
            : null;
        $category = [];
        try {
            $rows = UserCategory::query()
                ->where('user_id', $business->user_id)
                ->where('premium', '>', 0)
                ->get(['category_id', 'premium', 'rounding']);
        } catch (\Throwable) {
            //补列失败（数据库账号没有 ALTER 权限）时当作没有分类规则，别让整个分站的价格页报错
            $rows = [];
        }
        foreach ($rows as $row) {
            $category[(int)$row->category_id] = ['premium' => (int)$row->premium, 'rounding' => (int)$row->rounding];
        }
        return ['shop' => $shop, 'category' => $category];
    }

    /**
     * 一件商品在分站实际套用的加价：商品自己设了加价优先，其次它所在分类的规则，最后全店默认。
     * 规则只作用于主站商品（owner=0），分站自营商品由分站自己定价。没有加价返回 null。
     * @return array{premium:int, rounding:int, source:string}|null
     */
    public static function pick(array $rules, int $owner, int $categoryId, ?UserCommodity $custom): ?array
    {
        if ($custom && (int)$custom->premium > 0) {
            return ['premium' => (int)$custom->premium, 'rounding' => (int)$custom->rounding, 'source' => self::SOURCE_COMMODITY];
        }
        if ($owner !== 0) {
            return null;
        }
        if (isset($rules['category'][$categoryId])) {
            return $rules['category'][$categoryId] + ['source' => self::SOURCE_CATEGORY];
        }
        return $rules['shop'] !== null ? $rules['shop'] + ['source' => self::SOURCE_SHOP] : null;
    }

    /** 把 pick() 的结果包成一个不落库的实例，调用方照旧用 markup() */
    public static function fromRule(?array $rule): ?UserCommodity
    {
        if ($rule === null) {
            return null;
        }
        $markup = new self();
        $markup->premium = $rule['premium'];
        $markup->rounding = $rule['rounding'];
        return $markup;
    }

    /** 单件商品的便捷入口：这件商品在该分站套用的加价，没有返回 null */
    public static function effective(Business $business, Commodity $commodity): ?UserCommodity
    {
        $attributes = $commodity->getAttributes();
        if (!array_key_exists('owner', $attributes) || !array_key_exists('category_id', $attributes)) {
            //调用方可能只查了部分列；归属和分类决定套哪条规则，缺了就补查，不能按 0 猜
            $commodity = Commodity::query()->find($commodity->id, ['id', 'owner', 'category_id']) ?? $commodity;
            $attributes = $commodity->getAttributes();
        }
        return self::fromRule(self::pick(
            self::rulesOf($business),
            (int)($attributes['owner'] ?? 0),
            (int)($attributes['category_id'] ?? 0),
            self::getCustom($business->user_id, (int)$commodity->id)
        ));
    }

    /**
     * @param int|null $userId
     * @param int $commodityId
     * @return UserCommodity|null
     */
    public static function getCustom(?int $userId, int $commodityId): ?UserCommodity
    {
        if ($userId == 0 || !$userId) {
            return null;
        }

        return UserCommodity::query()->where("user_id", $userId)->where("commodity_id", $commodityId)->first();
    }
}