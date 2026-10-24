<?php

namespace App\Models;

use App\Domain\Eye\ProcessingStatus;
use App\Domain\Eye\Severity;
use App\Domain\Tenancy\BelongsToOrganisation;
use Database\Factories\SecurityEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Normalised security telemetry. Immutable once received, except for the
 * processing lifecycle fields written by the pipeline.
 *
 * @property string $id
 * @property string $organisation_id
 * @property string|null $monitored_asset_id
 * @property string|null $api_credential_id
 * @property string $event_type
 * @property string $source
 * @property Severity $severity
 * @property Carbon $occurred_at
 * @property Carbon $received_at
 * @property string|null $source_ip
 * @property array<string, mixed> $metadata
 * @property string|null $idempotency_key
 * @property string $fingerprint
 * @property ProcessingStatus $processing_status
 * @property int $processing_attempts
 * @property string|null $last_error
 * @property Carbon|null $processed_at
 * @property string|null $risk_score
 * @property string|null $verdict
 * @property string|null $threat_category
 * @property string|null $analysis_summary
 */
class SecurityEvent extends Model
{
    /** @use HasFactory<SecurityEventFactory> */
    use BelongsToOrganisation, HasFactory, HasUuids;

    protected $fillable = [
        'organisation_id', 'monitored_asset_id', 'api_credential_id', 'event_type', 'source',
        'severity', 'occurred_at', 'received_at', 'source_ip', 'metadata', 'idempotency_key',
        'fingerprint', 'processing_status',
    ];

    protected $attributes = [
        'metadata' => '{}',
        'processing_status' => 'received',
        'processing_attempts' => 0,
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'severity' => Severity::class,
            'processing_status' => ProcessingStatus::class,
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
            'processing_attempts' => 'integer',
            'analysed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MonitoredAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MonitoredAsset::class, 'monitored_asset_id')->withTrashed();
    }

    /** @return BelongsTo<ApiCredential, $this> */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(ApiCredential::class, 'api_credential_id');
    }

    /** @return HasMany<DetectionResult, $this> */
    public function detections(): HasMany
    {
        // Scores are in different units per detector (rule score, percentile,
        // robust z), so order by detector first, then by score within each.
        return $this->hasMany(DetectionResult::class)
            ->orderByRaw("CASE detector WHEN 'rule' THEN 0 WHEN 'anomaly_model' THEN 1 ELSE 2 END")
            ->orderByDesc('score');
    }

    /** @return BelongsTo<ModelVersion, $this> */
    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }

    /** @return HasOne<EventOutbox, $this> */
    public function outbox(): HasOne
    {
        return $this->hasOne(EventOutbox::class);
    }
}
