<?php
namespace App\MapaMaintenance;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Fleetbase\Ai\Contracts\AIProviderInterface;
use Fleetbase\Ai\Services\AiProviderManager;

class MaintenanceServiceProvider extends ServiceProvider
{
    public function register(): void {
        $this->mergeConfigFrom(__DIR__.'/config.php', 'mapa_maintenance');
        $this->app->booted(function () {
            if (!config('mapa_maintenance.enabled')) return;
            $this->app->bind(AiProviderManager::class, ProviderManager::class);
            $this->app->bind(AIProviderInterface::class, ProviderManager::class);
        });
    }
    public function boot(): void {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        $this->callAfterResolving(AiCapabilityRegistry::class, function ($registry) {
            if (config('mapa_maintenance.enabled')) $registry->register(new Capability());
        });
        Route::prefix('mapa/maintenance/v1')->middleware(['fleetbase.protected', RequestGuard::class, 'throttle:20,1'])->group(function () {
            Route::get('diagnostics', [Controller::class, 'diagnostics']);
            Route::get('history', [Controller::class, 'history']);
            Route::post('models/discover', [Controller::class, 'discover']);
            Route::post('repairs/preview', [Controller::class, 'preview']);
            Route::post('repairs/{id}/apply', [Controller::class, 'apply']);
        });
    }
}
