<?php

namespace App\Providers;

use App\Domain\Audit\AuthEventSubscriber;
use App\Domain\Identity\PermissionGate;
use App\Domain\Tenancy\TenantContext;
use App\Http\Middleware\AuthenticateIngestionClient;
use App\Http\Middleware\ResilientThrottleRequests;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: reset between HTTP requests and between queued jobs.
        $this->app->scoped(TenantContext::class);

        // Explicit fail-open / fail-closed behaviour when Redis is down.
        $this->app->bind(ThrottleRequests::class, ResilientThrottleRequests::class);
    }

    public function boot(): void
    {
        // Surface mass-assignment mistakes in development and tests.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        PermissionGate::register();
        Event::subscribe(AuthEventSubscriber::class);

        // Never carry a tenant context from one job into the next. (Queue workers
        // also reset scoped instances per job.) Skipped for the sync driver,
        // where the "job" runs inside the HTTP request and must not wipe the
        // request's own tenant.
        Queue::before(function (JobProcessing $event): void {
            if ($event->connectionName !== 'sync') {
                $this->app->make(TenantContext::class)->clear();
            }
        });

        $this->configurePasswords();
        $this->configureRateLimiting();
    }

    private function configurePasswords(): void
    {
        Password::defaults(function () {
            $rule = Password::min(12)->max(128)->letters()->mixedCase()->numbers();

            // Checks the k-anonymised SHA-1 prefix against Have I Been Pwned.
            // Only enabled in production, where outbound network is expected.
            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        VerifyEmail::createUrlUsing(function (User $user): string {
            return URL::temporarySignedRoute('api.v1.auth.verification.verify', now()->addMinutes(60), [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ]);
        });

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return config('kratos.frontend_url').'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });

        // Credential endpoints: per (identifier, IP) to slow targeted guessing,
        // and per IP to slow credential stuffing across many accounts.
        RateLimiter::for('auth', function (Request $request) {
            $identifier = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('auth:'.$identifier.'|'.$request->ip()),
                Limit::perMinute(30)->by('auth-ip:'.$request->ip()),
            ];
        });

        // Ingestion: per credential, resolved by AuthenticateIngestionClient.
        RateLimiter::for('ingest', function (Request $request) {
            $credential = $request->attributes->get(AuthenticateIngestionClient::CREDENTIAL_ATTRIBUTE);

            return Limit::perMinute((int) config('kratos.ingestion.rate_limit_per_minute'))
                ->by('ingest:'.($credential?->getKey() ?? $request->ip()));
        });

        RateLimiter::for('verification', function (Request $request) {
            return Limit::perMinute(6)->by('verify:'.($request->user()?->getAuthIdentifier() ?: $request->ip()));
        });
    }
}
