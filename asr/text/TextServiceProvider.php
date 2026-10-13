<?php
namespace App\MapaText;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
class TextServiceProvider extends ServiceProvider {
 public function register(): void {$this->mergeConfigFrom(__DIR__.'/config.php','mapa_text');}
 public function boot(): void {
  $this->loadMigrationsFrom(__DIR__.'/migrations');
  if($this->app->runningInConsole())$this->commands([TextTick::class]);
  $this->app->afterResolving(\Illuminate\Console\Scheduling\Schedule::class,fn($s)=>$s->command('mapa:text-tick')->everyMinute()->withoutOverlapping());
  Route::prefix('mapa/text/v1')->middleware('throttle:120,1')->group(function(){
   Route::get('desk',[TextController::class,'desk']);Route::post('settings',[TextController::class,'settings']);
   Route::get('connection',[WahaConnectionController::class,'status'])->middleware('throttle:30,1');
   Route::get('connection/qr',[WahaConnectionController::class,'qr'])->middleware('throttle:12,1');
   Route::post('connection/restart',[WahaConnectionController::class,'restart'])->middleware('throttle:6,1');
   Route::post('connection/switch',[WahaConnectionController::class,'switchAccount'])->middleware('throttle:3,1');
   Route::post('subscribe',[TextController::class,'subscribe']);Route::post('conversations/{id}',[TextController::class,'conversation']);
   Route::post('messages/{id}/retry',[TextController::class,'retry']);
   Route::get('meta',[TextController::class,'verify']);Route::post('meta',[TextController::class,'meta']);
   Route::post('oauth/authorize',[OAuthFlowGuard::class,'authorize'])->middleware('throttle:30,1');
   Route::post('bridge/events',[BridgeController::class,'events']);
  });
 }
}
