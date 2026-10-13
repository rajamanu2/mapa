<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MapaFleetController;

class MapaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(\App\MapaMobile\MobileServiceProvider::class);
        $this->app->register(\App\MapaVoice\VoiceServiceProvider::class);
        $this->app->register(\App\MapaText\TextServiceProvider::class);
        $this->app->register(\App\MapaMaintenance\MaintenanceServiceProvider::class);
    }

    public function boot(): void
    {
        config(['broadcasting.connections.socketcluster.options.headers.Origin' => config('mapa.socket_origin')]);

        Route::get('/mapa/health', fn () => response()->json([
            'service' => 'MAPA fleet adapter', 'contract' => 'mapa-v1', 'status' => 'ok',
        ])->header('Cache-Control', 'no-store'));

        Route::get('/mapa/v1/fleet', [MapaFleetController::class, 'index'])
            ->middleware('throttle:60,1');
        // Compatibility route for existing deployed consumers.
        Route::get('/asr/v1/fleet', [MapaFleetController::class, 'index'])->middleware('throttle:60,1');
    }
}


