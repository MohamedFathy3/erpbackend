<?php

use App\Http\Middleware\ForceJsonResponse;
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
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
           SnakeCaseMiddleware::class,
           ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'api' => ForceJsonResponse::class,
            'resolve.tenant' => \App\Http\Middleware\ResolveTenant::class,
            'module' => \App\Http\Middleware\CheckModuleEnabled::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'subscription' => \App\Http\Middleware\EnsureTenantSubscriptionActive::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        $schedule->command('trials:process')->dailyAt('01:00')->withoutOverlapping();
    })->create();
