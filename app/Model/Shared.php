<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $type
 * @property string $name
 * @property string $domain
 * @property string $app_id
 * @property string $app_key
 * @property string $create_time
 * @property float $balance
 * @property string $currency 上游站点货币代码（默认 CNY；老库无此列时读到 null 同样按 CNY）
 * @property string $currency_rate 结算汇率：1 上游货币 = ? 本站货币；0 = 按站点汇率自动
 */
class Shared extends Model
{
    /**
     * @var string
     */
    protected $table = 'shared';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer', 'type' => 'integer', 'balance' => 'float'];
}