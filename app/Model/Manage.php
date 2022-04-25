<?php
declare (strict_types=1);

namespace App\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $email
 * @property string $nickname
 * @property string $password
 * @property string $security_password
 * @property string $avatar
 * @property int $status
 * @property int $type
 * @property string $create_time
 * @property string $login_time
 * @property string $last_login_time
 * @property string $login_ip
 * @property string $last_login_ip
 * @property string $note
 */
class Manage extends Model
{
    /**
     * @var string
     */
    protected $table = 'manage';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer', 'type' => 'integer', 'status' => 'integer'];
}