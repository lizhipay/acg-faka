<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * @property float $amount
 * @property float $cost
 * @property int $commodity_id
 * @property int $card_id
 * @property string $contact
 * @property int $create_device
 * @property string $create_ip
 * @property string $create_time
 * @property int $delivery_status
 * @property int $id
 * @property int $owner
 * @property int $user_id
 * @property int $card_num
 * @property string $password
 * @property int $pay_id
 * @property string $pay_time
 * @property string $pay_url
 * @property string $secret
 * @property int $status
 * @property int $from
 * @property string $trade_no
 * @property string $widget
 * @property float $rent
 * @property float $premium
 * @property string $race
 * @property string $request_no
 */
class Order extends Model
{
    /**
     * @var string
     */
    protected $table = "order";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['amount' => 'float', 'cost' => 'float', 'rent' => 'float', 'premium' => 'float', 'user_id' => 'integer', 'from' => 'integer', 'commodity_id' => 'integer', 'card_id' => 'integer', 'card_num' => 'integer', 'create_device' => 'integer', 'delivery_status' => 'integer', 'id' => 'integer', 'owner' => 'integer', 'pay_id' => 'integer', 'status' => 'integer'];

    public function owner(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, "id", "owner");
    }

    public function user(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, "id", "user_id");
    }

    public function commodity(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Commodity::class, "id", "commodity_id");
    }

    public function pay(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Pay::class, "id", "pay_id");
    }

    public function card(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Card::class, "id", "card_id");
    }

    public function promote(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, "id", "from");
    }

    //优惠卷
    public function coupon(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Coupon::class, "id", "coupon_id");
    }
}