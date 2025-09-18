<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
 * @property int $substation_user_id
 * @property string $trade_no
 * @property string $widget
 * @property float $rent
 * @property float $rebate
 * @property float $premium
 * @property float $divide_amount
 * @property string $race
 * @property string $request_no
 * @property array $sku
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
    protected $casts = ['amount' => 'float', 'cost' => 'float', 'rebate' => 'float', 'divide_amount' => 'float', 'rent' => 'float', 'premium' => 'float', 'user_id' => 'integer', 'substation_user_id' => 'integer', 'from' => 'integer', 'commodity_id' => 'integer', 'card_id' => 'integer', 'card_num' => 'integer', 'create_device' => 'integer', 'delivery_status' => 'integer', 'id' => 'integer', 'owner' => 'integer', 'pay_id' => 'integer', 'status' => 'integer', 'sku' => 'json'];

    public function owner(): ?HasOne
    {
        return $this->hasOne(User::class, "id", "owner");
    }

    public function user(): ?HasOne
    {
        return $this->hasOne(User::class, "id", "user_id");
    }

    public function commodity(): ?HasOne
    {
        return $this->hasOne(Commodity::class, "id", "commodity_id");
    }

    public function pay(): ?HasOne
    {
        return $this->hasOne(Pay::class, "id", "pay_id");
    }

    public function card(): ?HasOne
    {
        return $this->hasOne(Card::class, "id", "card_id");
    }

    public function promote(): ?HasOne
    {
        return $this->hasOne(User::class, "id", "from");
    }

    public function substationUser(): HasOne
    {
        return $this->hasOne(User::class, "id", "substation_user_id");
    }

    //优惠卷
    public function coupon(): ?HasOne
    {
        return $this->hasOne(Coupon::class, "id", "coupon_id");
    }
}