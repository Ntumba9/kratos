<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    public function update(Request $request, AuditLogger $audit): Response
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['password' => $validated['password']])->save();

        // Keep the session that made the change; end all others.
        $currentSessionId = $request->hasSession() ? $request->session()->getId() : null;
        DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->when($currentSessionId, fn ($q) => $q->where('id', '!=', $currentSessionId))
            ->delete();

        $audit->record('auth.password.changed', $user);

        return ApiResponse::noContent();
    }
}
