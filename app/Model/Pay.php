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
 * @property int $pay_config_id
 * @property float $cost
 * @property int $cost_type
 * @property int $archived
 * @property string $min_amount 单笔最低支付金额（含手续费），0=不限
 * @property string $max_amount 单笔最高支付金额（含手续费），0=不限
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
    protected $casts = ['id' => 'integer', 'commodity' => 'integer', 'recharge' => 'integer', 'sort' => 'integer', 'equipment' => 'integer', 'cost_type' => 'integer', 'cost' => 'float', 'archived' => 'integer', 'pay_config_id' => 'integer'];
}