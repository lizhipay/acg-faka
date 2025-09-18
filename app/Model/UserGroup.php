<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property float $recharge
 * @property array $discount_config
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
    protected $casts = ['id' => 'integer', 'recharge' => 'float', 'discount_config' => 'json'];


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