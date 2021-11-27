<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $option
 * @property int $order_id
 */
class OrderOption extends Model
{
    /**
     * @var string
     */
    protected $table = "order_option";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer', 'order_id' => 'integer'];

    /**
     * @param int $orderId
     * @param array $option
     */
    public static function create(int $orderId, array $option): void
    {
        $orderOption = new self();
        $orderOption->order_id = $orderId;
        $orderOption->option = json_encode($option);
        $orderOption->save();
    }

    /**
     * @param int $orderId
     * @return array|null
     */
    public static function get(int $orderId): ?array
    {
        $orderOption = self::query()->where("order_id", $orderId)->first();
        if (!$orderOption) {
            return null;
        }
        return (array)json_decode($orderOption->option, true);
    }
}