<?php

use App\Http\Controllers\Api\V1\ApiCredentialController;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\AccessTokenController;
use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Ingest\EventIngestController;
use App\Http\Controllers\Api\V1\IntelligenceController;
use App\Http\Controllers\Api\V1\MembershipController;
use App\Http\Controllers\Api\V1\OrganisationController;
use App\Http\Controllers\Api\V1\Platform\PlatformOrganisationController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SecurityEventController;
use App\Http\Controllers\Api\V1\StatisticsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| KRATOS API v1
|--------------------------------------------------------------------------
|
| Every route here is documented in docs/api/openapi.yaml; the test
| Tests\Feature\OpenApiContractTest fails if the two drift apart.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {

    Route::get('health', HealthController::class)->name('health');

    // ------------------------------------------------- KRATOS EYE ingestion
    // Machine clients authenticated by HMAC request signature (ADR-0006).
    // Order matters: size limit before any work, authentication before the
    // per-credential rate limit.
    Route::prefix('ingest')
        ->name('ingest.')
        ->withoutMiddleware('throttle:api')
        ->middleware(['ingest.body_limit', 'ingest.auth', 'throttle:ingest'])
        ->group(function (): void {
            Route::post('events', [EventIngestController::class, 'store'])->name('events.store');
            Route::post('events/batch', [EventIngestController::class, 'batch'])->name('events.batch');
        });

    // ---------------------------------------------------------- guest auth
    Route::prefix('auth')->name('auth.')->middleware('throttle:auth')->group(function (): void {
        Route::post('register', RegisterController::class)->name('register');
        Route::post('login', [AuthenticatedSessionController::class, 'store'])->name('login');
        Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])->name('password.email');
        Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('password.reset');
    });

    // Signed link opened from the verification email (no session required).
    Route::get('auth/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('throttle:verification')
        ->name('auth.verification.verify');

    // ----------------------------------------------------- authenticated
    Route::middleware(['auth:sanctum', 'token.abilities'])->group(function (): void {

        Route::prefix('auth')->name('auth.')->group(function (): void {
            Route::get('me', [AuthenticatedSessionController::class, 'show'])->name('me');
            Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
            Route::post('email/verification-notification', [EmailVerificationController::class, 'send'])
                ->middleware('throttle:verification')
                ->name('verification.send');
            Route::put('password', [PasswordController::class, 'update'])->middleware('throttle:auth')->name('password.update');

            Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
            Route::delete('sessions', [SessionController::class, 'destroyOthers'])->name('sessions.destroy-others');
            Route::delete('sessions/{handle}', [SessionController::class, 'destroy'])
                ->where('handle', '[a-f0-9]{64}')
                ->name('sessions.destroy');

            Route::get('tokens', [AccessTokenController::class, 'index'])->name('tokens.index');
            Route::post('tokens', [AccessTokenController::class, 'store'])->middleware('verified.api')->name('tokens.store');
            Route::delete('tokens/{tokenId}', [AccessTokenController::class, 'destroy'])->name('tokens.destroy');
        });

        Route::middleware('verified.api')->group(function (): void {
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('intelligence/model', [IntelligenceController::class, 'model'])->name('intelligence.model');

            Route::get('organisations', [OrganisationController::class, 'index'])->name('organisations.index');
            Route::post('organisations', [OrganisationController::class, 'store'])->name('organisations.store');

            // Tenant-scoped resources. `organisation.member` resolves the
            // tenant and returns 404 to non-members.
            Route::prefix('organisations/{organisation}')
                ->whereUuid('organisation')
                ->middleware('organisation.member')
                ->scopeBindings()
                ->name('organisations.')
                ->group(function (): void {
                    Route::get('/', [OrganisationController::class, 'show'])->name('show');
                    Route::patch('/', [OrganisationController::class, 'update'])->name('update');

                    Route::get('members', [MembershipController::class, 'index'])->name('members.index');
                    Route::post('members', [MembershipController::class, 'store'])->name('members.store');
                    Route::patch('members/{membership}', [MembershipController::class, 'update'])
                        ->whereUuid('membership')->name('members.update');
                    Route::delete('members/{membership}', [MembershipController::class, 'destroy'])
                        ->whereUuid('membership')->name('members.destroy');

                    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

                    Route::get('stats/overview', [StatisticsController::class, 'overview'])->name('stats.overview');
                    Route::get('stats/intelligence', [IntelligenceController::class, 'statistics'])->name('stats.intelligence');

                    Route::get('detections', [IntelligenceController::class, 'detections'])->name('detections.index');
                    Route::get('detection-rules', [IntelligenceController::class, 'rules'])->name('detection-rules.index');
                    Route::patch('detection-rules/{key}', [IntelligenceController::class, 'updateRule'])
                        ->where('key', '[a-z0-9_]+')->name('detection-rules.update');
                    Route::delete('detection-rules/{key}', [IntelligenceController::class, 'resetRule'])
                        ->where('key', '[a-z0-9_]+')->name('detection-rules.reset');

                    Route::get('events', [SecurityEventController::class, 'index'])->name('events.index');
                    Route::get('events/{event}', [SecurityEventController::class, 'show'])->whereUuid('event')->name('events.show');
                    Route::post('events/{event}/replay', [SecurityEventController::class, 'replay'])->whereUuid('event')->name('events.replay');

                    Route::get('assets', [AssetController::class, 'index'])->name('assets.index');
                    Route::post('assets', [AssetController::class, 'store'])->name('assets.store');
                    Route::get('assets/{asset}', [AssetController::class, 'show'])->whereUuid('asset')->name('assets.show');
                    Route::patch('assets/{asset}', [AssetController::class, 'update'])->whereUuid('asset')->name('assets.update');
                    Route::delete('assets/{asset}', [AssetController::class, 'destroy'])->whereUuid('asset')->name('assets.destroy');

                    Route::get('api-credentials', [ApiCredentialController::class, 'index'])->name('api-credentials.index');
                    Route::post('api-credentials', [ApiCredentialController::class, 'store'])->name('api-credentials.store');
                    Route::delete('api-credentials/{credential}', [ApiCredentialController::class, 'destroy'])
                        ->whereUuid('credential')->name('api-credentials.destroy');
                });

            Route::prefix('platform')->name('platform.')->group(function (): void {
                Route::get('organisations', [PlatformOrganisationController::class, 'index'])->name('organisations.index');
            });
        });
    });
});
