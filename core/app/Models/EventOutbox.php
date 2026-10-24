<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pipeline-internal record (not exposed through the API). Not tenant scoped:
 * the relay works across all organisations by design and carries the
 * organisation into the job payload explicitly.
 *
 * @property int $id
 * @property string $security_event_id
 * @property string $organisation_id
 * @property Carbon $available_at
 * @property Carbon|null $published_at
 * @property int $publish_attempts
 * @property string|null $last_error
 */
class EventOutbox extends Model
{
    protected $table = 'event_outbox';

    public const UPDATED_AT = null;

    protected $fillable = ['security_event_id', 'organisation_id', 'available_at', 'published_at', 'publish_attempts', 'last_error'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'available_at' => 'datetime',
            'published_at' => 'datetime',
            'publish_attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<SecurityEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SecurityEvent::class, 'security_event_id');
    }
}
