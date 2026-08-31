<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property float $amount
 * @property float $pay_cost
 * @property string $create_ip
 * @property string $create_time
 * @property int $id
 * @property string $option
 * @property int $pay_id
 * @property string $pay_time
 * @property string $pay_url
 * @property int $status
 * @property string $trade_no
 * @property int $user_id
 */
class UserRecharge extends Model
{
    /**
     * @var string
     */
    protected $table = "user_recharge";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['amount' => 'float', 'gateway_amount' => 'float', 'pay_cost' => 'float', 'id' => 'integer', 'pay_id' => 'integer', 'status' => 'integer', 'user_id' => 'integer'];

    /**
     * @return HasOne|null
     */
    public function user(): ?HasOne
    {
        return $this->hasOne(User::class, "id", "user_id");
    }

    public function pay(): ?HasOne
    {
        return $this->hasOne(Pay::class, "id", "pay_id");
    }
}