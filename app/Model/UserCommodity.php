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
 * @property string $name
 */
class UserCommodity extends Model
{
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
    protected $casts = ['id' => 'integer', 'user_id' => 'integer', 'commodity_id' => 'integer', 'status' => 'integer', 'premium' => 'float'];


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