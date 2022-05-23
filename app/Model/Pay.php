<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $icon
 * @property string $code
 * @property int $commodity
 * @property int $recharge
 * @property int $sort
 * @property int $equipment
 * @property string $create_time
 * @property string $handle
 * @property float $cost
 * @property int $cost_type
 */
class Pay extends Model
{
    /**
     * @var string
     */
    protected $table = "pay";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer', 'commodity' => 'integer', 'recharge' => 'integer', 'sort' => 'integer', 'equipment' => 'integer', 'cost_type' => 'integer', 'cost' => 'float'];
}