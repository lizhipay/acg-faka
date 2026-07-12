<?php
declare(strict_types=1);

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $ticket_id
 * @property int $sender_type
 * @property int|null $sender_id
 * @property string $sender_name
 * @property int $kind
 * @property string|null $content
 * @property string $create_ip
 * @property string $create_time
 */
class TicketMessage extends Model
{
    public const SENDER_USER = 0;
    public const SENDER_MANAGE = 1;
    public const SENDER_SYSTEM = 2;

    public const KIND_CONTENT = 0;
    public const KIND_RESOLVED = 1;
    public const KIND_CLOSED = 2;

    protected $table = 'ticket_message';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'ticket_id' => 'integer',
        'sender_type' => 'integer',
        'sender_id' => 'integer',
        'kind' => 'integer',
    ];

    public function ticket(): ?HasOne
    {
        return $this->hasOne(Ticket::class, 'id', 'ticket_id');
    }
}
