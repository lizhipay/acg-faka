<?php
declare(strict_types=1);

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $message_id
 * @property int $user_id
 * @property string|null $read_time
 * @property string $create_time
 * @property SystemMessage|null $message
 */
class UserMessage extends Model
{
    protected $table = 'user_message';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'message_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function message(): ?HasOne
    {
        return $this->hasOne(SystemMessage::class, 'id', 'message_id');
    }

    public function user(): ?HasOne
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }
}
