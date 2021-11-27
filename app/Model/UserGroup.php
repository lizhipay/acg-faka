<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * @property float $discount
 * @property int $id
 * @property string $name
 * @property float $recharge
 */
class UserGroup extends Model
{
    /**
     * @var mixed
     */
    private static mixed $userGroups = null;

    /**
     * @var string
     */
    protected $table = "user_group";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['discount' => 'float', 'id' => 'integer', 'recharge' => 'float'];


    /**
     * @param float $recharge
     * @param bool $next
     * @return UserGroup|null
     */
    public static function get(float $recharge, bool $next = false): ?UserGroup
    {
        if (!self::$userGroups) {
            self::$userGroups = UserGroup::query()->orderBy("recharge", "desc")->get();
        }
        foreach (self::$userGroups as $inedx => $group) {
            if ($recharge >= $group->recharge) {
                if ($next) {
                    return self::$userGroups[$inedx - 1];
                }
                return $group;
            }
        }
        return null;
    }

}