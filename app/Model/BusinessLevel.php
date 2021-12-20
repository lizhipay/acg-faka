<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $icon
 * @property float $cost
 * @property float $accrual
 * @property int $substation
 * @property int $top_domain
 * @property float $price
 * @property int $supplier
 */
class BusinessLevel extends Model
{
    /**
     * @var string
     */
    protected $table = "business_level";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer', 'cost' => 'float', 'accrual' => 'float', 'substation' => 'integer', 'top_domain' => 'integer', 'supplier' => 'integer', 'price' => 'float'];
}