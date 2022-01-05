<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * @property float $amount
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
    protected $casts = ['amount' => 'float', 'id' => 'integer', 'pay_id' => 'integer', 'status' => 'integer', 'user_id' => 'integer'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne|null
     */
    public function user(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, "id", "user_id");
    }

    public function pay(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Pay::class, "id", "pay_id");
    }
}