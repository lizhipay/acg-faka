<?php
declare (strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property int $sort
 * @property string $create_time
 * @property int $owner
 * @property string $icon
 * @property int $status
 * @property int $hide
 */
class Category extends Model
{
    /**
     * @var string
     */
    protected $table = 'category';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer', 'status' => 'integer', 'sort' => 'integer', 'owner' => 'integer'];


    /*
     * 获取分类所属者
     */
    public function owner(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, "id", "owner");
    }

    /*
     * 获取分类下的所有商品
     */
    public function children(): ?\Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Commodity::class, "category_id", "id");
    }


    /**
     * @param \App\Model\UserGroup|null $group
     * @return array|null
     */
    public function getLevelConfig(?UserGroup $group): ?array
    {
        if (!$group) {
            return null;
        }
        $decode = (array)json_decode((string)$this->attributes['user_level_config'], true);
        if (!array_key_exists($group->id, $decode)) {
            return null;
        }
        return (array)$decode[$group->id];
    }

}