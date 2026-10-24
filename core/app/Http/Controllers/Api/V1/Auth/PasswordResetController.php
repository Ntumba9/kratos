<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    /**
     * Always returns the same response, whether or not the account exists,
     * so the endpoint cannot be used to enumerate registered emails.
     */
    public function sendLink(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'email', 'max:254']]);

        Password::broker()->sendResetLink(['email' => mb_strtolower($validated['email'])]);

        return ApiResponse::accepted('If an account exists for that address, a password reset link has been sent.');
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker()->reset(
            [
                'email' => mb_strtolower($validated['email']),
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $validated['token'],
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // A reset implies the old password may be compromised: end
                // every existing browser session for this account.
                DB::table('sessions')->where('user_id', $user->getKey())->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error('invalid_reset_token', 'This password reset link is invalid or has expired.', 422);
        }

        return ApiResponse::ok(['message' => 'Password has been reset. You can now sign in.']);
    }
}
