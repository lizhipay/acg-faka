<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property float $amount
 * @property float $cost
 * @property int $type
 * @property int $card
 * @property string $create_time
 * @property string $arrive_time
 * @property int $status
 * @property string $message
 */
class Cash extends Model
{
    /**
     * @var string
     */
    protected $table = "cash";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer', 'user_id' => 'integer', 'amount' => 'float',  'cost' => 'float', 'type' => 'integer', 'card' => 'integer', 'status' => 'integer'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne|null
     */
    public function user(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, "id", "user_id");
    }

}