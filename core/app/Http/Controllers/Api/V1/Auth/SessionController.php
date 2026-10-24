<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lists and revokes the caller's browser sessions. A session ID is the
 * secret inside the session cookie, so it is never returned: clients see an
 * opaque SHA-256 handle and revoke sessions by that handle.
 */
class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $current = $this->currentSessionId($request);

        $sessions = DB::table('sessions')
            ->where('user_id', $request->user()->getKey())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $s) => [
                'id' => self::handle($s->id),
                'ip_address' => $s->ip_address,
                'user_agent' => $s->user_agent,
                'last_active_at' => Carbon::createFromTimestamp($s->last_activity)->toIso8601String(),
                'current' => $current !== null && hash_equals($s->id, $current),
            ]);

        return ApiResponse::ok($sessions->values());
    }

    public function destroy(Request $request, string $handle, AuditLogger $audit): Response|JsonResponse
    {
        $current = $this->currentSessionId($request);

        $sessionId = DB::table('sessions')
            ->where('user_id', $request->user()->getKey())
            ->pluck('id')
            ->first(fn (string $id) => hash_equals(self::handle($id), $handle));

        if ($sessionId === null) {
            return ApiResponse::error('not_found', 'The requested resource was not found.', 404);
        }

        if ($current !== null && hash_equals($sessionId, $current)) {
            return ApiResponse::error('conflict', 'Use logout to end the current session.', 409);
        }

        DB::table('sessions')->where('id', $sessionId)->delete();
        $audit->record('auth.session.revoked', $request->user(), ['session' => $handle]);

        return ApiResponse::noContent();
    }

    public function destroyOthers(Request $request, AuditLogger $audit): Response
    {
        $count = DB::table('sessions')
            ->where('user_id', $request->user()->getKey())
            ->when($this->currentSessionId($request), fn ($q, $id) => $q->where('id', '!=', $id))
            ->delete();

        $audit->record('auth.session.revoked_others', $request->user(), ['count' => $count]);

        return ApiResponse::noContent();
    }

    private function currentSessionId(Request $request): ?string
    {
        return $request->hasSession() ? $request->session()->getId() : null;
    }

    private static function handle(string $sessionId): string
    {
        return hash_hmac('sha256', $sessionId, (string) config('app.key'));
    }
}
