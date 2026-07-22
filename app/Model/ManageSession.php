<?php
declare(strict_types=1);

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $manage_id
 * @property string $session_hash
 * @property string $device_type
 * @property string $device_name
 * @property string $user_agent
 * @property string $login_ip
 * @property string $last_ip
 * @property string $created_time
 * @property string $last_seen_time
 * @property string $expires_time
 * @property string|null $revoked_time
 */
class ManageSession extends Model
{
    protected $table = 'manage_session';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'manage_id' => 'integer',
    ];
}
