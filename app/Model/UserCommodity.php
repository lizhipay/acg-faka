<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

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