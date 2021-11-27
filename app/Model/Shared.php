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