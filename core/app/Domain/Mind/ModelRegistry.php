<?php

namespace App\Domain\Mind;

use App\Models\ModelVersion;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mirrors KRATOS MIND model versions into model_versions so evidence can
 * reference the exact model that produced it, and so the dashboard can show
 * the model card even while MIND is unavailable.
 */
class ModelRegistry
{
    /**
     * Per-process memo (workers are long-lived). Deliberately not the Redis
     * cache: a Redis blip must not turn into a failed analysis.
     *
     * @var array<string, string>
     */
    private static array $ids = [];

    public function __construct(private readonly MindClient $mind) {}

    /**
     * @param  array{name: string, version: string, algorithm: string, artifact_sha256: string}  $model
     */
    public function idFor(array $model): string
    {
        $key = "{$model['name']}:{$model['version']}:{$model['artifact_sha256']}";

        return self::$ids[$key] ??= (function () use ($model): string {
            $existing = ModelVersion::query()->where('name', $model['name'])->where('version', $model['version'])->first();

            if ($existing !== null) {
                if ($existing->artifact_sha256 !== $model['artifact_sha256']) {
                    // Same version label, different artifact: never silently conflate them.
                    Log::warning('mind.model.hash_changed', ['version' => $model['version']]);
                    $existing->forceFill(['artifact_sha256' => $model['artifact_sha256']])->save();
                }
                $existing->forceFill(['last_seen_at' => now()])->save();

                return $existing->getKey();
            }

            return $this->sync($model)->getKey();
        })();
    }

    public static function forget(): void
    {
        self::$ids = [];
    }

    /**
     * Fetch full metadata (metrics, limitations, model card) from MIND.
     *
     * @param  array{name: string, version: string, algorithm: string, artifact_sha256: string}|null  $expected
     */
    public function sync(?array $expected = null): ModelVersion
    {
        $remote = $this->mind->activeModel();
        $md = $remote['metadata'];

        if ($expected !== null && ($md['version'] !== $expected['version'] || $md['artifact_sha256'] !== $expected['artifact_sha256'])) {
            // MIND switched models between the analysis and this call; record what the analysis reported.
            Log::warning('mind.model.version_race', ['expected' => $expected['version'], 'active' => $md['version']]);
        }

        return ModelVersion::query()->updateOrCreate(
            ['name' => $md['name'], 'version' => $md['version']],
            [
                'algorithm' => $md['algorithm'],
                'artifact_sha256' => $md['artifact_sha256'],
                'feature_schema' => $md['feature_schema'],
                'hyperparameters' => $md['hyperparameters'],
                'training_dataset' => $md['training_dataset'],
                'metrics' => $md['metrics'],
                'limitations' => $md['limitations'],
                'model_card' => $remote['model_card'] ?? null,
                'trained_at' => $md['trained_at'],
                'last_seen_at' => now(),
            ],
        );
    }

    /**
     * The model card shown in the dashboard: live from MIND when reachable,
     * otherwise the last recorded version.
     */
    public function current(): ?ModelVersion
    {
        try {
            return $this->sync();
        } catch (Throwable $e) {
            Log::info('mind.model.served_from_cache', ['exception' => $e::class]);

            return ModelVersion::query()->orderByDesc('last_seen_at')->first();
        }
    }
}
