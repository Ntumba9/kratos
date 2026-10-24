<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A KRATOS MIND model version, recorded the first time CORE sees it in an
 * analysis response. Global (models are not tenant data).
 *
 * @property string $id
 * @property string $name
 * @property string $version
 * @property string $algorithm
 * @property string $artifact_sha256
 * @property array<string, mixed> $metrics
 * @property array<string, mixed> $training_dataset
 * @property list<string> $limitations
 */
class ModelVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'version', 'algorithm', 'artifact_sha256', 'feature_schema', 'hyperparameters',
        'training_dataset', 'metrics', 'limitations', 'model_card', 'trained_at', 'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'feature_schema' => 'array',
            'hyperparameters' => 'array',
            'training_dataset' => 'array',
            'metrics' => 'array',
            'limitations' => 'array',
            'trained_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
