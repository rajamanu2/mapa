<?php
namespace App\MapaMobile;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class MobileServiceProvider extends ServiceProvider
{
    public function register(): void { $this->mergeConfigFrom(__DIR__.'/config.php', 'mapa_mobile'); }
    public function boot(): void
    {
        // The caller field belongs to native console orders, including edits.
        // Validate it even when phone login is temporarily disabled.
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->pushMiddleware(CallerPhoneRequest::class);
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->pushMiddleware(SourceNativeWriteGuard::class);
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        if($this->app->runningInConsole())$this->commands([SourceDispatchDrainCommand::class]);
        if(config('mapa_mobile.source_dispatch_coordination_enabled',false)){
            foreach([\Fleetbase\FleetOps\Models\Order::class,\Fleetbase\FleetOps\Models\Driver::class,\Fleetbase\FleetOps\Models\Vehicle::class] as $model)$model::observe(SourceNativeAssignmentObserver::class);
        }
        if (!config('mapa_mobile.enabled')) return;
        // Disabled by default. Registering routes does not enable provider traffic.
        \Fleetbase\FleetOps\Models\Order::observe(PushAssignmentObserver::class);
        RateLimiter::for('mapa-mobile-login', fn (Request $r) => [
            $this->limit(20, 'mapa-login-ip:'.$r->ip()),
            $this->limit(5, 'mapa-login-account:'.hash('sha256', strtolower(trim((string)$r->input('email'))))),
        ]);
        RateLimiter::for('mapa-mobile', fn (Request $r) => [
            // The fixed IP bucket cannot be escaped by rotating invalid tokens.
            $this->limit(1200, 'mapa-mobile-ip:'.$r->ip()),
            $this->limit(120, 'mapa-mobile-session:'.hash('sha256', (string)$r->bearerToken())),
        ]);
        RateLimiter::for('mapa-intake-launch',fn(Request $r)=>[
            $this->limit(30,'mapa-intake-launch-ip:'.$r->ip()),
            $this->limit(3,'mapa-intake-launch-ticket:'.hash('sha256',(string)$r->input('ticket'))),
        ]);
        RateLimiter::for('mapa-mobile-enrollment', fn (Request $r) => [
            $this->limit(30, 'mapa-enrollment-ip:'.$r->ip()),
            Limit::perHour(60)->by('mapa-enrollment-hour:'.$r->ip())->response(fn(Request $r,array $h)=>response()->json(['error'=>'Registration limit reached. Contact control.'],429,$h)->header('Cache-Control','no-store')),
            Limit::perHour(10)->by('mapa-enrollment-email:'.hash('sha256',strtolower(trim((string)$r->input('email')))))->response(fn(Request $r,array $h)=>response()->json(['error'=>'Registration limit reached. Contact control.'],429,$h)->header('Cache-Control','no-store')),
        ]);
        RateLimiter::for('mapa-whatsapp-webhook', fn(Request $r)=>[
            $this->limit(600,'mapa-whatsapp-webhook-global'),
            $this->limit(600,'mapa-whatsapp-webhook-ip:'.$r->ip()),
        ]);
        RateLimiter::for('mapa-mobile-camera',fn(Request $r)=>[
            $this->limit(60,'mapa-camera-ip:'.$r->ip()),
            $this->limit(10,'mapa-camera-session:'.hash('sha256',(string)$r->bearerToken())),
        ]);
        // Each native review request revalidates its own console credential.
        // Never exchange it for a detached mobile session or expose duty/GPS here.
        Route::get('int/v1/mapa/crew-review/gps-map',[ProviderGpsMapController::class,'console'])
            ->middleware(['auth:sanctum',BoundedRequest::class,'throttle:mapa-mobile']);
        Route::get('int/v1/mapa/crew-review/gps-map/journey/{vehicleId}',[ProviderGpsMapController::class,'journey'])
            ->middleware(['auth:sanctum',BoundedRequest::class,'throttle:mapa-mobile']);
        Route::get('int/v1/mapa/source-inventory/{dataset?}', [SourceInventoryController::class,'index'])
            ->middleware(['auth:sanctum',BoundedRequest::class,'throttle:mapa-mobile']);
        Route::get('int/v1/mapa/source-profiles/{dataset}/{id}', [SourceProfileController::class,'show'])
            ->middleware(['auth:sanctum',BoundedRequest::class,'throttle:mapa-mobile']);
        Route::post('int/v1/mapa/source-inventory/refresh', [SourceInventoryController::class,'refresh'])
            ->middleware(['auth:sanctum',BoundedRequest::class,'throttle:mapa-mobile']);
        Route::get('int/v1/mapa/source-commands', [SourceCommandController::class,'index'])
            ->middleware(['auth:sanctum',BoundedRequest::class,'throttle:mapa-mobile']);
        Route::post('int/v1/mapa/source-commands', [SourceCommandController::class,'store'])
            ->middleware(['auth:sanctum',BoundedRequest::class,'throttle:mapa-mobile']);
        Route::get('int/v1/mapa/source-workflows', [SourceWorkflowController::class,'index'])
            ->middleware(['auth:sanctum',BoundedRequest::class,'throttle:mapa-mobile']);
        Route::post('int/v1/mapa/source-workflows', [SourceWorkflowController::class,'store'])
            ->middleware(['auth:sanctum',BoundedRequest::class,'throttle:mapa-mobile']);
        Route::get('int/v1/mapa/source-masters', [SourceMasterController::class,'index'])
            ->middleware(['auth:sanctum',BoundedRequest::class,'throttle:mapa-mobile']);
        Route::post('int/v1/mapa/source-masters', [SourceMasterController::class,'store'])
            ->middleware(['auth:sanctum',BoundedRequest::class,'throttle:mapa-mobile']);
        Route::get('int/v1/mapa/source-connections', [SourceConnectionController::class,'index'])
            ->middleware(['auth:sanctum',BoundedRequest::class,'throttle:mapa-mobile']);
        Route::post('int/v1/mapa/source-connections', [SourceConnectionController::class,'store'])
            ->middleware(['auth:sanctum',BoundedRequest::class,'throttle:mapa-mobile']);
        Route::get('int/v1/mapa/source-projections/{dataset}/{id}', [SourceConnectionController::class,'projection'])
            ->middleware(['auth:sanctum',BoundedRequest::class,'throttle:mapa-mobile']);
        Route::get('int/v1/mapa/source-dashboard/{mode}', [SourceDashboardController::class,'index'])
            ->middleware(['auth:sanctum',BoundedRequest::class,'throttle:mapa-mobile']);
        Route::get('int/v1/mapa/source-reconciliation', [SourceReconciliationController::class,'index'])
            ->middleware(['auth:sanctum',BoundedRequest::class,'throttle:mapa-mobile']);
        Route::post('int/v1/mapa/source-reconciliation/{event}/acknowledge', [SourceReconciliationController::class,'acknowledge'])
            ->middleware(['auth:sanctum',BoundedRequest::class,'throttle:mapa-mobile']);
        Route::prefix('int/v1/mapa/crew-review')->middleware(['auth:sanctum',BoundedRequest::class,'throttle:mapa-mobile'])->group(function(){
            Route::post('intake-launch',[ConsoleIntakeLaunch::class,'launch']);
            Route::get('identity',[ConsoleReviewSession::class,'identity']);
            Route::get('intake',[ConsoleReviewSession::class,'intake']);
            Route::get('source-calls',[ConsoleReviewSession::class,'sourceCalls']);
            Route::get('enrollments',[ConsoleReviewSession::class,'index']);
            Route::get('enrollments/options',[ConsoleReviewSession::class,'options']);
            Route::get('trip-reports',[ConsoleReviewSession::class,'reports']);
            Route::get('media/{id}',[ConsoleReviewSession::class,'media']);
            Route::post('enrollments/{id}/ambulance',[ConsoleReviewSession::class,'ambulance']);
            Route::post('enrollments/{id}/hospital',[ConsoleReviewSession::class,'hospital']);
            Route::post('enrollments/{id}/approve',[ConsoleReviewSession::class,'approve']);
            Route::post('enrollments/{id}/reject',[ConsoleReviewSession::class,'reject']);
            Route::post('enrollments/{id}/revoke',[ConsoleReviewSession::class,'revoke']);
        });
        Route::prefix('mapa/mobile/v1')->middleware(BoundedRequest::class)->group(function () {
            Route::get('tracking/whatsapp/webhook', [WhatsAppWebhookController::class,'verify'])->middleware('throttle:mapa-whatsapp-webhook');
            Route::post('tracking/whatsapp/webhook', [WhatsAppWebhookController::class,'receive'])->middleware('throttle:mapa-whatsapp-webhook');
            Route::post('enrollments', [EnrollmentController::class,'register'])->middleware('throttle:mapa-mobile-enrollment');
            Route::post('login', [MobileController::class,'login'])->middleware('throttle:mapa-mobile-login');
            Route::post('intake-launch/exchange', [ConsoleIntakeLaunch::class,'exchange'])->middleware('throttle:mapa-intake-launch');
            Route::middleware('throttle:mapa-mobile')->group(function () {
                Route::get('enrollment', [EnrollmentController::class,'status']);
                Route::post('connect', [EnrollmentController::class,'connect']);
                Route::post('enrollment/revoke', [EnrollmentController::class,'disconnect']);
                Route::get('enrollments', [EnrollmentController::class,'index']);
                Route::get('enrollments/options', [EnrollmentController::class,'options']);
                Route::post('enrollments/{id}/ambulance', [EnrollmentController::class,'ambulance']);
                Route::post('enrollments/{id}/hospital', [EnrollmentController::class,'hospital']);
                Route::post('enrollments/{id}/approve', [EnrollmentController::class,'approve']);
                Route::post('enrollments/{id}/reject', [EnrollmentController::class,'reject']);
                Route::post('enrollments/{id}/revoke', [EnrollmentController::class,'revoke']);
                Route::get('trip-reports', [EnrollmentController::class,'reports']);
                Route::get('media/{id}', [EnrollmentController::class,'media']);
                Route::post('orders/{id}/patient-onboard', [HospitalHandoverController::class,'onboard']);
                Route::get('hospital/incoming', [HospitalHandoverController::class,'incoming']);
                Route::get('hospital/overview', [HospitalHandoverController::class,'overview']);
                Route::post('hospital/handovers/{id}/acknowledge', [HospitalHandoverController::class,'acknowledge']);
                Route::post('tracking/calls/{id}/links', [ConnectedTrackingController::class,'issue']);
                Route::get('tracking/calls/{id}/locations', [ConnectedTrackingController::class,'locations']);
                Route::post('tracking/calls/{id}/pickup', [ConnectedTrackingController::class,'pickup']);
                Route::post('tracking/links/{id}/revoke', [ConnectedTrackingController::class,'revoke']);
                Route::get('tracking/view', [ConnectedTrackingController::class,'view']);
                Route::post('tracking/location', [ConnectedTrackingController::class,'location']);
                Route::get('tracking/devices', [ConnectedTrackingController::class,'devices']);
                Route::get('tracking/provider-map', [ProviderGpsMapController::class,'mobile']);
                Route::post('tracking/devices', [ConnectedTrackingController::class,'createDevice']);
                Route::post('tracking/devices/{id}/revoke', [ConnectedTrackingController::class,'revokeDevice']);
                Route::post('tracking/ingest', [ConnectedTrackingController::class,'ingest']);
                Route::get('tracking/calls/{id}/whatsapp', [CallerMessageController::class,'forCall']);
                Route::get('tracking/whatsapp', [CallerMessageController::class,'status']);
                Route::post('tracking/calls/{id}/whatsapp', [CallerMessageController::class,'send']);
                Route::get('tracking/sms', [CallerSmsController::class,'status']);
                Route::get('tracking/calls/{id}/sms', [CallerSmsController::class,'forCall']);
                Route::post('tracking/calls/{id}/sms', [CallerSmsController::class,'send']);
                Route::post('tracking/calls/{id}/sms/auto', [CallerSmsController::class,'auto']);
                Route::get('capabilities', [MobileController::class,'capabilities']);
                Route::get('snapshot', [MobileController::class,'snapshot']);
                Route::get('travel', [MobileController::class,'travel']);
                Route::get('push', [PushController::class,'status']);
                Route::post('push/register', [PushController::class,'register']);
                Route::post('push/unregister', [PushController::class,'unregister']);
                Route::post('logout', [MobileController::class,'logout']);
                Route::post('duty', [MobileController::class,'duty']);
                Route::post('gps', [MobileController::class,'gps']);
                Route::post('orders/{id}/arrivals', [MobileController::class,'arrivals']);
                Route::post('orders/{id}/ack', [MobileController::class,'ack']);
                Route::post('orders/{id}/activity', [MobileController::class,'activity'])->middleware('throttle:mapa-mobile-camera');
            });
        });
    }
    private function limit(int $attempts, string $key): Limit
    {
        return Limit::perMinute($attempts)->by($key)->response(fn(Request $r, array $headers) =>
            response()->json(['error'=>'Too many requests. Try again shortly.'],429,$headers)->header('Cache-Control','no-store'));
    }
}
