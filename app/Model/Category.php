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
     * 获取上级分类
     */
    public function parent(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Category::class, "id", "pid");
    }

    /**
     * 加载全部分类的扁平映射：id => ['name' => 分类名, 'pid' => 上级ID(顶级为0)]。
     * 仅一次查询，供批量解析分类层级路径时复用，避免无限极向上追溯产生的 N+1 查询。
     * @return array<int, array{name: string, pid: int}>
     */
    public static function flatMap(): array
    {
        $map = [];
        foreach (self::query()->get(['id', 'name', 'pid']) as $category) {
            $map[(int)$category->id] = [
                'name' => (string)$category->name,
                'pid' => (int)$category->pid,
            ];
        }
        return $map;
    }

    /**
     * 解析某个分类的完整层级路径（顶级分类 -> 子分类 -> ... -> 自身）。
     * 支持无限极分类；数据异常（自引用/环）时以最大深度 100 兜底，防止死循环。
     * @param int $categoryId 目标分类ID
     * @param array<int, array{name: string, pid: int}>|null $flatMap 可传入 self::flatMap() 的结果以复用查询；为 null 时内部自行加载
     * @return string[] 由顶级到自身的分类名称数组；分类不存在时返回空数组
     */
    public static function resolvePath(int $categoryId, ?array $flatMap = null): array
    {
        $flatMap ??= self::flatMap();
        $names = [];
        $currentId = $categoryId;
        $depth = 0;
        while ($currentId > 0 && isset($flatMap[$currentId]) && $depth < 100) {
            array_unshift($names, $flatMap[$currentId]['name']);
            $currentId = $flatMap[$currentId]['pid'];
            $depth++;
        }
        return $names;
    }


    /**
     * @param UserGroup|null $group
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