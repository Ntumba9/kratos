<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of detection evidence for an event: a rule hit, the anomaly
 * model's verdict, or a statistical outlier. Replaced wholesale when an event
 * is re-analysed, so redelivery or replay never duplicates evidence.
 *
 * @property string $id
 * @property string $organisation_id
 * @property string $security_event_id
 * @property string $detector
 * @property string|null $rule_key
 * @property string|null $severity
 * @property string|null $category
 * @property string $score
 * @property string $explanation
 * @property array<string, mixed> $details
 */
class DetectionResult extends Model
{
    use BelongsToOrganisation, HasUuids;

    public const UPDATED_AT = null;

    public const DETECTOR_RULE = 'rule';

    public const DETECTOR_ANOMALY = 'anomaly_model';

    public const DETECTOR_STATISTICAL = 'statistical';

    protected $fillable = [
        'organisation_id', 'security_event_id', 'detector', 'rule_key', 'model_version_id',
        'severity', 'category', 'score', 'explanation', 'details',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<SecurityEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SecurityEvent::class, 'security_event_id');
    }
}
