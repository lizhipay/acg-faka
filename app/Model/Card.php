<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * @property int $commodity_id
 * @property string $create_time
 * @property string $draft
 * @property int $id
 * @property int $order_id
 * @property int $owner
 * @property string $purchase_time
 * @property string $secret
 * @property string $note
 * @property int $status
 * @property string $race
 */
class Card extends Model
{
    /**
     * @var string
     */
    protected $table = "card";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['commodity_id' => 'integer', 'id' => 'integer', 'order_id' => 'integer', 'owner' => 'integer', 'status' => 'integer'];


    public function owner(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, "id", "owner");
    }

    public function commodity(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Commodity::class, "id", "commodity_id");
    }

    public function order(): ?\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Order::class, "id", "order_id");
    }
}