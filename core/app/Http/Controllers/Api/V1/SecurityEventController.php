<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Eye\EventReplayer;
use App\Domain\Eye\ProcessingStatus;
use App\Domain\Eye\Severity;
use App\Http\Controllers\Controller;
use App\Http\Resources\SecurityEventResource;
use App\Http\Responses\ApiResponse;
use App\Http\Support\ListQuery;
use App\Models\Organisation;
use App\Models\SecurityEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecurityEventController extends Controller
{
    /**
     * GET …/events?filter[severity]=high,critical&filter[event_type]=…&filter[source]=…
     *   &filter[status]=…&filter[asset_id]=…&filter[source_ip]=…&filter[from]=…&filter[to]=…
     *   &filter[q]=…&sort=-occurred_at&page=…&per_page=…
     */
    public function index(Request $request, Organisation $organisation): JsonResponse
    {
        $this->authorize('events.view', $organisation);

        $filters = $request->validate([
            'filter' => ['sometimes', 'array'],
            'filter.severity' => ['sometimes', 'string', 'max:64', 'regex:/^(info|low|medium|high|critical)(,(info|low|medium|high|critical))*$/'],
            'filter.event_type' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z0-9_.]+$/'],
            'filter.source' => ['sometimes', 'string', 'max:128'],
            'filter.status' => ['sometimes', 'string', 'in:'.implode(',', ProcessingStatus::values())],
            'filter.asset_id' => ['sometimes', 'uuid'],
            'filter.source_ip' => ['sometimes', 'ip'],
            'filter.from' => ['sometimes', 'date'],
            'filter.to' => ['sometimes', 'date', 'after_or_equal:filter.from'],
            'filter.q' => ['sometimes', 'string', 'max:120'],
            'filter.verdict' => ['sometimes', 'string', 'regex:/^(benign|suspicious|malicious)(,(benign|suspicious|malicious))*$/'],
            'filter.min_risk' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ])['filter'] ?? [];

        $list = ListQuery::fromRequest($request, ['occurred_at', 'received_at', 'severity', 'event_type', 'risk_score'], '-occurred_at');

        $query = SecurityEvent::query()
            ->with('asset')
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->whereIn('severity', explode(',', $v)))
            ->when($filters['event_type'] ?? null, fn ($q, $v) => $q->where('event_type', $v))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('processing_status', $v))
            ->when($filters['asset_id'] ?? null, fn ($q, $v) => $q->where('monitored_asset_id', $v))
            ->when($filters['source_ip'] ?? null, fn ($q, $v) => $q->where('source_ip', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('occurred_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('occurred_at', '<=', $v))
            ->when($filters['verdict'] ?? null, fn ($q, $v) => $q->whereIn('verdict', explode(',', $v)))
            ->when(isset($filters['min_risk']), fn ($q) => $q->where('risk_score', '>=', $filters['min_risk']))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->whereRaw('lower(event_type) LIKE ?', [$like])
                    ->orWhereRaw('lower(source) LIKE ?', [$like])
                    ->orWhereRaw('host(source_ip) LIKE ?', [$like]));
            });

        // Severity sorts by rank (critical highest), not alphabetically.
        $bySeverity = function ($q, string $direction): void {
            $ranks = collect(Severity::cases())->map(fn (Severity $s) => "WHEN '{$s->value}' THEN {$s->rank()}")->implode(' ');
            $q->orderByRaw("CASE severity {$ranks} END ".($direction === 'desc' ? 'DESC' : 'ASC'))
                ->orderBy('occurred_at', 'desc');
        };

        // Unscored events sort last in either direction.
        $byRisk = fn ($q, string $direction) => $q->orderByRaw('risk_score '.($direction === 'desc' ? 'DESC' : 'ASC').' NULLS LAST');

        return ApiResponse::paginated($list->paginate($query, ['severity' => $bySeverity, 'risk_score' => $byRisk]), SecurityEventResource::class);
    }

    public function show(Organisation $organisation, string $event): JsonResponse
    {
        $this->authorize('events.view', $organisation);

        $model = SecurityEvent::query()->with(['asset', 'credential', 'outbox', 'detections', 'modelVersion'])->findOrFail($event);

        return ApiResponse::ok((new SecurityEventResource($model))->detailed());
    }

    public function replay(Organisation $organisation, string $event, EventReplayer $replayer): JsonResponse
    {
        $this->authorize('events.manage', $organisation);

        $model = $replayer->replay(SecurityEvent::query()->findOrFail($event));

        return ApiResponse::ok((new SecurityEventResource($model->load(['asset', 'credential', 'outbox', 'detections', 'modelVersion'])))->detailed(), 202);
    }
}
