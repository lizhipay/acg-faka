<?php
declare(strict_types=1);

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $ticket_no
 * @property int $user_id
 * @property int $type
 * @property int $priority
 * @property int $status
 * @property string $title
 * @property int|null $commodity_id
 * @property string|null $commodity_name
 * @property int|null $order_id
 * @property string|null $order_trade_no
 * @property int $order_source
 * @property int|null $proof_upload_id
 * @property string|null $proof_path
 * @property int|null $last_message_id
 * @property int|null $last_sender_type
 * @property string|null $last_message_excerpt
 * @property string|null $last_message_time
 * @property int $user_unread
 * @property int $manage_unread
 * @property int|null $closed_by
 * @property string|null $closed_time
 * @property string $create_time
 * @property string $update_time
 */
class Ticket extends Model
{
    public const TYPE_PRE_SALE = 0;
    public const TYPE_AFTER_SALE = 1;

    public const PRIORITY_LOW = 0;
    public const PRIORITY_MEDIUM = 1;
    public const PRIORITY_HIGH = 2;

    public const STATUS_PENDING_ADMIN = 0;
    public const STATUS_PENDING_USER = 1;
    public const STATUS_RESOLVED = 2;
    public const STATUS_CLOSED = 3;

    public const ORDER_SOURCE_NONE = 0;
    public const ORDER_SOURCE_MEMBER = 1;
    public const ORDER_SOURCE_GUEST = 2;

    protected $table = 'ticket';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'type' => 'integer',
        'priority' => 'integer',
        'status' => 'integer',
        'commodity_id' => 'integer',
        'order_id' => 'integer',
        'order_source' => 'integer',
        'proof_upload_id' => 'integer',
        'last_message_id' => 'integer',
        'last_sender_type' => 'integer',
        'user_unread' => 'integer',
        'manage_unread' => 'integer',
        'closed_by' => 'integer',
    ];

    public function user(): ?HasOne
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function commodity(): ?HasOne
    {
        return $this->hasOne(Commodity::class, 'id', 'commodity_id');
    }

    public function order(): ?HasOne
    {
        return $this->hasOne(Order::class, 'id', 'order_id');
    }

    public function closedBy(): ?HasOne
    {
        return $this->hasOne(Manage::class, 'id', 'closed_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id', 'id');
    }
}
