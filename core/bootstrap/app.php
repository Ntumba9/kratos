<?php

use App\Http\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateIngestionClient;
use App\Http\Middleware\EnforceTokenAbilities;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\LimitRequestBody;
use App\Http\Middleware\ResolveOrganisation;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        // Sanctum: first-party SPA requests (by Origin/Referer) get session
        // cookies and CSRF protection; other clients must use bearer tokens.
        $middleware->statefulApi();
        $middleware->throttleApi('api');

        // Traffic arrives through the Nginx edge proxy on the Docker network.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '172.16.0.0/12,10.0.0.0/8,192.168.0.0/16'));

        $middleware->alias([
            'organisation.member' => ResolveOrganisation::class,
            'verified.api' => EnsureEmailIsVerified::class,
            'token.abilities' => EnforceTokenAbilities::class,
            'ingest.body_limit' => LimitRequestBody::class,
            'ingest.auth' => AuthenticateIngestionClient::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(new ApiExceptionRenderer);
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'token']);
    })->create();
