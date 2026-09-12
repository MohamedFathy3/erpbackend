<?php

namespace App\Providers;

use Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use App\Http\Middleware\AdminMiddleware;
use App\Models\Invoice;
use App\Models\ManufacturingOrder;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceReturn;
use App\Models\ReturnInvoice;
use App\Models\WorkflowTransaction;
use App\Services\AI\AIResponseService;
use App\Services\AI\DatabaseSchemaService;
use App\Services\AI\GeminiService;
use App\Services\AI\ReadOnlyQueryService;
use App\Services\AI\SQLGeneratorService;
use App\Services\AI\SQLValidatorService;
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GeminiService::class);
        $this->app->singleton(DatabaseSchemaService::class);
        $this->app->singleton(SQLGeneratorService::class);
        $this->app->singleton(SQLValidatorService::class);
        $this->app->singleton(ReadOnlyQueryService::class);
        $this->app->singleton(AIResponseService::class);
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
            if (class_exists(IdeHelperServiceProvider::class)) {
                $this->app->register(IdeHelperServiceProvider::class);
            }

        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('signup', fn ($request) => Limit::perMinute(5)->by($request->ip()));
        Route::aliasMiddleware('admin', AdminMiddleware::class);

        $track = static function ($model, string $event): void {
            WorkflowTransaction::capture(
                strtolower(class_basename($model)) . ':' . $model->getKey() . ':' . $event . ':' . $model->updated_at?->timestamp,
                $model,
                $event,
                ['status' => $model->status ?? null, 'updated_at' => $model->updated_at?->toISOString()]
            );
        };

        foreach ([Invoice::class, SalesInvoice::class, PurchaseInvoice::class, ReturnInvoice::class, SalesInvoiceReturn::class, PurchaseReturn::class, ManufacturingOrder::class, Project::class] as $trackedModel) {
            $trackedModel::created(fn ($model) => $track($model, 'created'));
            $trackedModel::updated(fn ($model) => $track($model, 'updated'));
        }

        ResetPassword::createUrlUsing(static function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });
    }
}
