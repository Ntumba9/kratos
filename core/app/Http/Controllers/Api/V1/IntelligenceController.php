<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mind\DetectionRuleCatalog;
use App\Domain\Mind\IntelligenceStatistics;
use App\Domain\Mind\ModelRegistry;
use App\Http\Controllers\Controller;
use App\Http\Resources\DetectionResultResource;
use App\Http\Responses\ApiResponse;
use App\Http\Support\ListQuery;
use App\Models\DetectionResult;
use App\Models\Organisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;

class IntelligenceController extends Controller
{
    public function rules(Organisation $organisation, DetectionRuleCatalog $catalog): JsonResponse
    {
        $this->authorize('detections.view', $organisation);

        return ApiResponse::ok($catalog->effective());
    }

    public function updateRule(Request $request, Organisation $organisation, string $key, DetectionRuleCatalog $catalog): JsonResponse
    {
        $this->authorize('detections.manage', $organisation);
        abort_unless(array_key_exists($key, config('kratos.detection_rules')), 404);

        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'parameters' => ['sometimes', 'array'],
            'parameters.*' => ['numeric'],
        ]);

        return ApiResponse::ok($catalog->update($key, Arr::only($validated, ['enabled', 'parameters']), $request->user()));
    }

    public function resetRule(Organisation $organisation, string $key, DetectionRuleCatalog $catalog): Response
    {
        $this->authorize('detections.manage', $organisation);
        abort_unless(array_key_exists($key, config('kratos.detection_rules')), 404);

        $catalog->reset($key);

        return ApiResponse::noContent();
    }

    /**
     * GET …/detections?filter[detector]=rule&filter[rule_key]=…&filter[from]=…&sort=-score
     */
    public function detections(Request $request, Organisation $organisation): JsonResponse
    {
        $this->authorize('detections.view', $organisation);

        $filters = $request->validate([
            'filter' => ['sometimes', 'array'],
            'filter.detector' => ['sometimes', 'in:rule,anomaly_model,statistical'],
            'filter.rule_key' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'filter.from' => ['sometimes', 'date'],
            'filter.to' => ['sometimes', 'date', 'after_or_equal:filter.from'],
        ])['filter'] ?? [];

        $list = ListQuery::fromRequest($request, ['created_at', 'score'], '-created_at');

        $query = DetectionResult::query()
            ->with('event')
            ->when($filters['detector'] ?? null, fn ($q, $v) => $q->where('detector', $v))
            ->when($filters['rule_key'] ?? null, fn ($q, $v) => $q->where('rule_key', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v));

        return ApiResponse::paginated($list->paginate($query), DetectionResultResource::class);
    }

    public function statistics(Organisation $organisation, IntelligenceStatistics $statistics): JsonResponse
    {
        $this->authorize('detections.view', $organisation);

        return ApiResponse::ok($statistics->compute());
    }

    /**
     * The active anomaly model and its (synthetic) evaluation. Models are
     * global, not tenant data; any verified user may read the card.
     */
    public function model(ModelRegistry $models): JsonResponse
    {
        $model = $models->current();

        if ($model === null) {
            return ApiResponse::error('model_unavailable', 'No model information is available yet; KRATOS MIND has not been reached.', 503);
        }

        return ApiResponse::ok([
            'name' => $model->name,
            'version' => $model->version,
            'algorithm' => $model->algorithm,
            'artifact_sha256' => $model->artifact_sha256,
            'trained_at' => $model->trained_at?->toIso8601String(),
            'feature_schema' => $model->feature_schema,
            'hyperparameters' => $model->hyperparameters,
            'training_dataset' => $model->training_dataset,
            'metrics' => $model->metrics,
            'limitations' => $model->limitations,
            'model_card' => $model->model_card,
            'synthetic_evaluation' => (bool) ($model->training_dataset['synthetic'] ?? true),
            'last_seen_at' => $model->last_seen_at?->toIso8601String(),
        ]);
    }
}
