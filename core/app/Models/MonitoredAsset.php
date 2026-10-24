<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganisation;
use Database\Factories\MonitoredAssetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organisation_id
 * @property string $name
 * @property string $asset_type
 * @property string|null $hostname
 * @property string|null $ip_address
 * @property string $environment
 * @property string $criticality
 * @property list<string> $tags
 * @property Carbon|null $last_seen_at
 */
class MonitoredAsset extends Model
{
    /** @use HasFactory<MonitoredAssetFactory> */
    use BelongsToOrganisation, HasFactory, HasUuids, SoftDeletes;

    public const TYPES = ['server', 'application', 'database', 'network_device', 'endpoint', 'cloud_service'];

    public const ENVIRONMENTS = ['production', 'staging', 'development'];

    public const CRITICALITIES = ['low', 'medium', 'high', 'critical'];

    protected $fillable = [
        'organisation_id', 'name', 'asset_type', 'hostname', 'ip_address',
        'environment', 'criticality', 'tags', 'description',
    ];

    protected $attributes = [
        'tags' => '[]',
        'environment' => 'production',
        'criticality' => 'medium',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return HasMany<SecurityEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SecurityEvent::class);
    }
}
