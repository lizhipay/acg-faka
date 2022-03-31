<?php
declare(strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int $category_id
 * @property string $name
 * @property int $status
 */
class UserCategory extends Model
{
    /**
     * @var string
     */
    protected $table = "user_category";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer' , 'user_id' => 'integer' , 'category_id' => 'integer' , 'status' => 'integer'];
}