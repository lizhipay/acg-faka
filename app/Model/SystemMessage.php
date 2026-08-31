<?php
declare(strict_types=1);

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $audience_type
 * @property int|null $audience_id
 * @property string $audience_name
 * @property string $title
 * @property string $content
 * @property string $summary
 * @property string|null $jump_url
 * @property int $recipient_count
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property string $manage_name
 * @property string $update_manage_name
 * @property string $create_time
 * @property string $update_time
 */
class SystemMessage extends Model
{
    public const AUDIENCE_ALL = 0;
    public const AUDIENCE_GROUP = 1;
    public const AUDIENCE_USER = 2;

    protected $table = 'system_message';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'audience_type' => 'integer',
        'audience_id' => 'integer',
        'recipient_count' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function receipts(): HasMany
    {
        return $this->hasMany(UserMessage::class, 'message_id', 'id');
    }
}
