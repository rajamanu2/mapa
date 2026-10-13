<?php
namespace App\MapaVoice;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
class VoiceServiceProvider extends ServiceProvider {
    public function register(): void {$this->mergeConfigFrom(__DIR__.'/config.php','mapa_voice');}
    public function boot(): void {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        if($this->app->runningInConsole())$this->commands([AutomaticDispatchCommand::class]);
        $this->app->afterResolving(\Illuminate\Console\Scheduling\Schedule::class,function($schedule){
            $schedule->command('mapa:automatic-dispatch-tick')->everyTenSeconds()->withoutOverlapping();
        });
        if(!config('mapa_voice.enabled'))return;
        RateLimiter::for('mapa-voice',fn($r)=>Limit::perMinute(180)->by('voice-ip:'.$r->ip())->response(fn()=>response()->json(['error'=>'Too many requests. Try again shortly.'],429)->header('Cache-Control','no-store')));
        Route::prefix('mapa/voice/v1')->middleware([BoundedRequest::class,'throttle:mapa-voice'])->group(function(){
            Route::post('bridge/events',[VoiceController::class,'bridge']);
            Route::get('source-calls',[VoiceController::class,'sourceCalls']); Route::post('source-calls/{id}/open',[VoiceController::class,'sourceOpen']);
            Route::get('calls',[VoiceController::class,'index']); Route::get('calls/{id}',[VoiceController::class,'show']);
            Route::get('command-desk',[VoiceController::class,'commandDesk']);
            Route::get('automatic/settings',[VoiceController::class,'automaticSettings']);
            Route::post('automatic/settings',[VoiceController::class,'automaticSettingsSave']);
            Route::post('calls/{id}/automatic/start',[VoiceController::class,'automaticStart']);
            Route::post('calls/{id}/automatic/stop',[VoiceController::class,'automaticStop']);
            Route::post('demo',[VoiceController::class,'demo']);
            Route::post('calls/manual',[VoiceController::class,'manual']);
            Route::get('locations',[VoiceController::class,'locations']);
            Route::post('locations/nearby',[VoiceController::class,'nearby']);
            foreach(['review','dispatch','dismiss','extract'] as $action)Route::post('calls/{id}/'.$action,[VoiceController::class,$action]);
        });
        Route::prefix('mapa/mobile/v1')->middleware([BoundedRequest::class,'throttle:mapa-voice'])->group(function(){
            Route::get('dispatch-offers',[VoiceController::class,'offers']);
            Route::post('dispatch-offers/{id}/respond',[VoiceController::class,'offerResponse']);
        });
    }
}
