<?php

use App\Http\Middleware\BranchScope;
use App\Http\Middleware\AuthenticateBiometricAgent;
use App\Http\Middleware\CheckModuleEnabled;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnforceRoutePermission;
use App\Http\Middleware\ManageTenantAccess;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\EnsureTenantSubscriptionActive;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SnakeCaseMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // API authentication is performed with Sanctum personal access tokens.
        // Keep browser-session / CSRF middleware on the web stack only; the SPA
        // and API are hosted on different sites and do not share session cookies.
        $middleware->api(prepend: [
            SnakeCaseMiddleware::class,
            ForceJsonResponse::class,
        ]);
        $middleware->api(append: [
            \App\Http\Middleware\AuditTrailMiddleware::class,
            CheckModuleEnabled::class,
            EnforceRoutePermission::class,
            EnsureTenantSubscriptionActive::class,
        ]);
        $middleware->validateCsrfTokens(except: ['iclock/*']);

        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'api' => ForceJsonResponse::class,
            'branch.scope' => BranchScope::class,
            'module.enabled' => CheckModuleEnabled::class,
            'permission' => CheckPermission::class,
            'manage.tenant.access' => ManageTenantAccess::class,
            'resolve.tenant' => ResolveTenant::class,
            'auth.biometric-agent' => AuthenticateBiometricAgent::class,
            'subscription' => EnsureTenantSubscriptionActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
