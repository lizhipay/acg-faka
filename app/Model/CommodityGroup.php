<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property array $commodity_list
 */
class CommodityGroup extends Model
{
    /**
     * @var string
     */
    protected $table = "commodity_group";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer', 'commodity_list' => 'json'];
}