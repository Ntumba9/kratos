<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmailVerificationController extends Controller
{
    /**
     * Resend the verification email to the authenticated user.
     */
    public function send(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::ok(['message' => 'Email address already verified.']);
        }

        $user->sendEmailVerificationNotification();

        return ApiResponse::accepted('Verification email queued.');
    }

    /**
     * Target of the signed link in the verification email. The link is
     * opened by a browser, so outcomes are redirects into KRATOS COMMAND.
     * Possession of a valid, unexpired signature for this user ID and email
     * hash is the proof; an active session is not required.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $redirect = fn (string $status) => redirect()->away(config('kratos.frontend_url').'/verify-email?status='.$status);

        if (! $request->hasValidSignature()) {
            return $redirect('invalid');
        }

        $user = Str::isUuid($id) ? User::find($id) : null;

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return $redirect('invalid');
        }

        if ($user->hasVerifiedEmail()) {
            return $redirect('already-verified');
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return $redirect('verified');
    }
}
