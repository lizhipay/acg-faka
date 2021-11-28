<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

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
 * @property int $lot_status
 * @property int $password_status
 * @property string $lot_config
 * @property int $coupon
 * @property int $shared_id
 * @property string $shared_code
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
        'status' => 'integer',
        'owner' => 'integer',
        'integral' => 'integer',
        'delivery_way' => 'integer',
        'delivery_auto_mode' => 'integer',
        'contact_type' => 'integer',
        'sort' => 'integer',
        'lot_status' => 'integer',
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
    ];

    public function owner(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, "id", "owner");
    }

    public function shared(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Shared::class, "id", "shared_id");
    }

    public function category(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Category::class, "id", "category_id");
    }

    //获取卡密
    public function card(): ?\Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Card::class, 'commodity_id', 'id');
    }
}