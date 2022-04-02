<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * @property string $code
 * @property int $commodity_id
 * @property string $create_time
 * @property string $expire_time
 * @property int $id
 * @property float $money
 * @property string $trade_no
 * @property int $owner
 * @property string $service_time
 * @property string $note
 * @property int $status
 * @property int $life
 * @property int $use_life
 * @property int $mode
 * @property int $category_id
 * @property string $race
 */
class Coupon extends Model
{
    /**
     * @var string
     */
    protected $table = "coupon";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['commodity_id' => 'integer', 'id' => 'integer', 'category_id' => 'integer', 'mode' => 'integer', 'money' => 'float', 'owner' => 'integer', 'status' => 'integer', 'life' => 'integer', 'use_life' => 'integer'];

    public function owner(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, "id", "owner");
    }

    public function commodity(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Commodity::class, "id", "commodity_id");
    }

    public function category(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Category::class, "id", "category_id");
    }
}