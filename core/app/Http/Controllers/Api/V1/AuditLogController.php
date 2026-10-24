<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Responses\ApiResponse;
use App\Http\Support\ListQuery;
use App\Models\AuditLog;
use App\Models\Organisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * GET …/audit-logs?filter[action]=membership.&filter[actor_user_id]=…&filter[from]=…&filter[to]=…
     *
     * `filter[action]` is a prefix match, so `membership.` returns every
     * membership event.
     */
    public function index(Request $request, Organisation $organisation): JsonResponse
    {
        $this->authorize('audit_logs.view', $organisation);

        $filters = $request->validate([
            'filter' => ['sometimes', 'array'],
            'filter.action' => ['sometimes', 'string', 'max:96', 'regex:/^[a-z0-9_.]+$/'],
            'filter.actor_user_id' => ['sometimes', 'uuid'],
            'filter.from' => ['sometimes', 'date'],
            'filter.to' => ['sometimes', 'date', 'after_or_equal:filter.from'],
        ])['filter'] ?? [];

        $list = ListQuery::fromRequest($request, ['created_at', 'action'], '-created_at');

        // Tenant isolation layer 2: AuditLog is tenant scoped, so this query
        // is constrained to the organisation resolved by the middleware.
        $query = AuditLog::query()
            ->with('actor')
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', 'like', addcslashes($action, '%_\\').'%'))
            ->when($filters['actor_user_id'] ?? null, fn ($q, $actor) => $q->where('actor_user_id', $actor))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', $to));

        return ApiResponse::paginated($list->paginate($query), AuditLogResource::class);
    }
}
