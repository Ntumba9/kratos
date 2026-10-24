<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssetResource;
use App\Http\Responses\ApiResponse;
use App\Http\Support\ListQuery;
use App\Models\MonitoredAsset;
use App\Models\Organisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssetController extends Controller
{
    public function index(Request $request, Organisation $organisation): JsonResponse
    {
        $this->authorize('assets.view', $organisation);

        $filters = $request->validate([
            'filter' => ['sometimes', 'array'],
            'filter.asset_type' => ['sometimes', Rule::in(MonitoredAsset::TYPES)],
            'filter.criticality' => ['sometimes', Rule::in(MonitoredAsset::CRITICALITIES)],
            'filter.environment' => ['sometimes', Rule::in(MonitoredAsset::ENVIRONMENTS)],
            'filter.q' => ['sometimes', 'string', 'max:120'],
            'filter.include_retired' => ['sometimes', 'boolean'],
        ])['filter'] ?? [];

        $list = ListQuery::fromRequest($request, ['name', 'criticality', 'last_seen_at', 'created_at'], 'name');

        $query = MonitoredAsset::query()
            ->withCount(['events as events_last_24h' => fn ($q) => $q->where('received_at', '>=', now()->subDay())])
            ->when(filter_var($filters['include_retired'] ?? false, FILTER_VALIDATE_BOOL), fn ($q) => $q->withTrashed())
            ->when($filters['asset_type'] ?? null, fn ($q, $v) => $q->where('asset_type', $v))
            ->when($filters['criticality'] ?? null, fn ($q, $v) => $q->where('criticality', $v))
            ->when($filters['environment'] ?? null, fn ($q, $v) => $q->where('environment', $v))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
                $q->where(fn ($w) => $w->whereRaw('lower(name) LIKE ?', [$like])->orWhereRaw('lower(hostname) LIKE ?', [$like]));
            });

        return ApiResponse::paginated($list->paginate($query), AssetResource::class);
    }

    public function store(Request $request, Organisation $organisation, AuditLogger $audit): JsonResponse
    {
        $this->authorize('assets.manage', $organisation);

        $asset = MonitoredAsset::create($this->validated($request));
        $audit->record('asset.created', $asset, ['name' => $asset->name]);

        return ApiResponse::created(new AssetResource($asset->refresh()));
    }

    public function show(Organisation $organisation, string $asset): JsonResponse
    {
        $this->authorize('assets.view', $organisation);

        $model = MonitoredAsset::withTrashed()
            ->withCount(['events as events_last_24h' => fn ($q) => $q->where('received_at', '>=', now()->subDay())])
            ->findOrFail($asset);

        return ApiResponse::ok(new AssetResource($model));
    }

    public function update(Request $request, Organisation $organisation, string $asset, AuditLogger $audit): JsonResponse
    {
        $this->authorize('assets.manage', $organisation);

        $model = MonitoredAsset::query()->findOrFail($asset);
        $model->fill($this->validated($request, $model));
        $changes = $model->getDirty();
        $model->save();

        if ($changes !== []) {
            $audit->record('asset.updated', $model, ['changed' => array_keys($changes)]);
        }

        return ApiResponse::ok(new AssetResource($model));
    }

    /**
     * Retire (soft delete). Historical events keep their link to the asset.
     */
    public function destroy(Organisation $organisation, string $asset, AuditLogger $audit): Response
    {
        $this->authorize('assets.manage', $organisation);

        $model = MonitoredAsset::query()->findOrFail($asset);
        $model->delete();
        $audit->record('asset.retired', $model, ['name' => $model->name]);

        return ApiResponse::noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?MonitoredAsset $existing = null): array
    {
        $required = $existing ? 'sometimes' : 'required';

        $validated = $request->validate([
            'name' => [$required, 'string', 'min:2', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/'],
            'asset_type' => [$required, Rule::in(MonitoredAsset::TYPES)],
            'hostname' => ['sometimes', 'nullable', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([A-Za-z0-9-]{1,63}\.)*[A-Za-z0-9-]{1,63}$/'],
            'ip_address' => ['sometimes', 'nullable', 'ip'],
            'environment' => ['sometimes', Rule::in(MonitoredAsset::ENVIRONMENTS)],
            'criticality' => ['sometimes', Rule::in(MonitoredAsset::CRITICALITIES)],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'distinct', 'max:40', 'regex:/^[a-z0-9][a-z0-9:_-]*$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if (isset($validated['name'])) {
            $duplicate = MonitoredAsset::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower($validated['name'])])
                ->when($existing, fn ($q) => $q->whereKeyNot($existing->getKey()))
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['name' => ['An active asset with this name already exists.']]);
            }
        }

        return $validated;
    }
}
