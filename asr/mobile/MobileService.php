<?php
namespace App\MapaMobile;

use Fleetbase\Models\User;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Request-local facade. Never mutates Auth, session tenant or Sanctum state. */
class MobileService
{
    public readonly PrivateMedia $privateMedia;
    public function __construct(?PrivateMedia $media=null) { $this->privateMedia=$media??new PrivateMedia; }
    public static function fail(string $message, int $status = 403): never { throw new HttpException($status, $message); }
    public function company(): string
    {
        $id = (string) config('mapa.company_uuid');
        if (!config('mapa_mobile.enabled') || !preg_match('/^[a-f0-9-]{36}$/iD', $id)
            || !Schema::hasTable('mapa_mobile_sessions')) self::fail('MAPA mobile is not configured.', 503);
        return $id;
    }
    public function login(Request $request): array
    {
        $input = $request->validate(['email'=>'required|email|max:200','password'=>'required|string|max:1024','device_name'=>'sometimes|string|max:100']);
        $company = $this->company();
        // Membership/role never come from the login body or native global-admin bypass.
        $user = User::where('email', strtolower(trim($input['email'])))->first();
        $dummy = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid = Hash::check($input['password'], $user?->password ?? $dummy);
        if (!$valid || !$user) self::fail('Invalid credentials or mobile access unavailable.', 401);
        return DB::transaction(function () use ($user,$company,$input) {
            $binding = DB::table('mapa_mobile_bindings')->where(['company_uuid'=>$company,'user_uuid'=>$user->uuid])->lockForUpdate()->first();
            if (!$binding) self::fail('Invalid credentials or mobile access unavailable.', 401);
            $fresh = User::where('uuid',$user->uuid)->lockForUpdate()->first();
            if (!$fresh || !hash_equals($user->password,$fresh->password)) self::fail('Credentials changed. Sign in again.',401);
            $user = $fresh;
            $context = $this->resolve($binding, $user, true);
            return $this->issueSession($context,$input['device_name']??'Android');
        });
    }
    protected function issueSession(array $context,string $deviceName): array
    {
        $binding=$context['binding']; $user=$context['user']; $company=$context['company'];
        $token = bin2hex(random_bytes(32)); $now = (int) round(microtime(true)*1000);
        $expiry = $now + min(720, max(5, (int) config('mapa_mobile.session_minutes',720)))*60000;
        $old = DB::table('mapa_mobile_sessions')->where('binding_id',$binding->id)->whereNull('revoked_at')->orderByDesc('created_at')->get();
        foreach ($old->slice(4) as $session) DB::table('mapa_mobile_sessions')->where('token_hash',$session->token_hash)->update(['revoked_at'=>$now]);
        DB::table('mapa_mobile_sessions')->insert(['token_hash'=>hash('sha256',$token),'binding_id'=>$binding->id,
            'company_uuid'=>$company,'user_uuid'=>$user->uuid,'binding_revision'=>$binding->revision,
            'credential_hash'=>hash('sha256',$user->password),'scope_hash'=>$this->scopeHash($context),'device_name'=>$deviceName,
            'expires_at'=>$expiry,'created_at'=>now(),'updated_at'=>now()]);
        return [...$this->capabilities($context),'token'=>$token,'expires_at'=>$expiry];
    }
    public function context(Request $request, bool $lock = false): array
    {
        $token = (string)$request->bearerToken();
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) self::fail('Sign in to MAPA.',401);
        return $this->contextFromSessionHash(hash('sha256',$token),$lock);
    }
    /** Internal worker authentication: never accepts an HTTP session hash. */
    public function contextFromSessionHash(string $hash, bool $lock = false): array
    {
        $company = $this->company();
        if (!preg_match('/^[a-f0-9]{64}$/D',$hash)) self::fail('Invalid mobile session.',401);
        $query = DB::table('mapa_mobile_sessions')->where('token_hash',$hash);
        $session = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$session || $session->revoked_at !== null || $session->expires_at <= (int)round(microtime(true)*1000)) self::fail('Session expired or revoked.',401);
        $query = DB::table('mapa_mobile_bindings')->where('id',$session->binding_id);
        $binding = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$binding || !Policy::bindingMatches((array)$binding,(array)$session,$company)) self::fail('Mobile access changed. Sign in again.',401);
        $query = User::where('uuid',$session->user_uuid);
        $user = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$user || !hash_equals($session->credential_hash,hash('sha256',$user->password))) self::fail('Session credentials changed.',401);
        $context = $this->resolve($binding,$user,$lock);
        if (!hash_equals($session->scope_hash,$this->scopeHash($context))) self::fail('Native assignment changed. Sign in again.',401);
        if($session->device_name==='MAPA parent-bound intake') {
            $credential=\Illuminate\Support\Facades\Cache::get('mapa:intake:parent:'.$session->token_hash);
            if(!is_string($credential)) self::fail('Operations launch expired. Sign in again.',401);
            $parent=Request::create('/');
            $parent->headers->set('Authorization','Bearer '.\Illuminate\Support\Facades\Crypt::decryptString($credential));
            $native=(new ConsoleEnrollmentService)->context($parent,$lock);
            if($native['user']->uuid!==$context['user']->uuid||$native['company']!==$context['company']) self::fail('Operations identity changed.',401);
        }
        return [...$context,'token_hash'=>$session->token_hash];
    }
    protected function scopeHash(array $c): string
    {
        return Policy::scopeHash($c['company'],$c['user']->uuid,$c['binding']->role,$c['driver']?->uuid,$c['vehicle']?->uuid,$c['place']?->uuid);
    }
    protected function resolve(object $binding, User $user, bool $lock = false): array
    {
        $company = $this->company();
        $membership = CompanyUser::where('company_uuid',$company)->where('user_uuid',$user->uuid)->where('status','active');
        if (!$binding->enabled || $binding->company_uuid !== $company || !in_array($binding->role,Policy::ROLES,true)
            || $user->status !== 'active' || !Company::where('uuid',$company)->exists()
            || !($lock ? $membership->lockForUpdate() : $membership)->first()) self::fail('Mobile membership is not active.');
        $this->requirePasswordOnlyEligible($user, $company, $lock);
        if ($binding->role === 'superadmin' && ($user->type !== 'admin' || !in_array($user->uuid,config('mapa_mobile.superadmin_user_uuids',[]),true))) self::fail('Platform mobile role is not authorized.');
        $driver = $vehicle = $place = null;
        if ($binding->role === 'crew') {
            $q = $this->native(Driver::class,$company)->where('uuid',$binding->driver_uuid)->where('user_uuid',$user->uuid);
            $driver = ($lock ? $q->lockForUpdate() : $q)->first();
            if (!$driver) self::fail('No active native driver is bound to this account.');
            if ($driver->vehicle_uuid) {
                $q = $this->native(Vehicle::class,$company)->where('uuid',$driver->vehicle_uuid);
                $vehicle = ($lock ? $q->lockForUpdate() : $q)->first();
                if (!$vehicle || ($vehicle->driver_uuid && $vehicle->driver_uuid !== $driver->uuid)
                    || $this->native(Driver::class,$company)->where('vehicle_uuid',$vehicle->uuid)->where('uuid','<>',$driver->uuid)->exists()) self::fail('Native vehicle assignment is inconsistent.');
            }
        }
        if ($binding->role === 'crew' && $binding->place_uuid) {
            $q=$this->native(Place::class,$company)->where('uuid',$binding->place_uuid);
            $place=($lock?$q->lockForUpdate():$q)->first();
            if(!$place) self::fail('Native crew hospital/base changed. Contact control.');
        }
        if ($binding->role === 'hospital') {
            $place = $this->native(Place::class,$company)->where('uuid',$binding->place_uuid)->first();
            if (!$place) self::fail('No native receiving place is bound to this account.');
        }
        return compact('binding','user','company','driver','vehicle','place');
    }
    protected function requirePasswordOnlyEligible(User $user, string $company, bool $lock): void
    {
        // Native login also refuses customers and unverified non-admin users.
        if ($user->type === 'customer' || ($user->isNotVerified() && $user->isNotAdmin()))
            self::fail('Native account verification is required. Use the console.', 403);
        // Read the actual native settings directly and without cached values.
        // TwoFactorAuth getters CREATE default settings when absent; a mobile
        // eligibility check must never change the user's authentication policy.
        $keys = ['user.'.$user->getKey().'.2fa', 'company.'.$company.'.2fa', 'system.2fa'];
        if ($user->company_uuid && $user->company_uuid !== $company) $keys[] = 'company.'.$user->company_uuid.'.2fa';
        $query = DB::table('settings')->whereIn('key', $keys);
        foreach (($lock ? $query->lockForUpdate() : $query)->get() as $setting) {
            $value = json_decode($setting->value, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($value)) self::fail('Native authentication policy requires console review.', 403);
            foreach (['enabled','enforced'] as $flag)
                if (isset($value[$flag]) && !in_array($value[$flag], [false,0,'0','false',''], true))
                    self::fail('Native multi-factor authentication is required. Use the console; mobile MFA is not available yet.', 403);
        }
        // Preserve explicit native/custom account security restrictions too.
        // The current upstream release stores MFA in settings; older account
        // metadata and operator-enforced reset flags must never be bypassed.
        foreach (['two_factor_enabled','mfa_required','password_reset_required','force_password_reset','must_change_password','password_expired'] as $flag)
            foreach ([$user->getAttribute($flag), data_get($user->meta, $flag), data_get($user->options, $flag)] as $value)
                if ($value !== null && !in_array($value, [false,0,'0','false',''], true))
                    self::fail('Native account security action is required. Use the console before mobile sign-in.', 403);
    }
    // Native scope traits rely on a console session. This facade supplies an explicit
    // server-owned tenant predicate and retains soft-delete exclusion instead.
    protected function native(string $model, string $company)
    {
        return $model::withoutGlobalScopes()->where('company_uuid',$company)->whereNull('deleted_at');
    }
    public function capabilities(array $c): array
    {
        $capabilities = Policy::capabilities($c['binding']->role,(bool)$c['vehicle']);
        $capabilities['activities'] = $c['binding']->role === 'crew' && (bool)$c['vehicle'] && (bool)config('mapa_mobile.trips_enabled',true);
        $capabilities['arrivals'] = $capabilities['activities'] && class_exists(TripArrivalService::class);
        $capabilities['photo_upload'] = $c['binding']->role === 'crew' && (bool)$c['vehicle'] && (bool)config('mapa_mobile.completion_evidence_enabled',false) && Schema::hasTable('mapa_mobile_trip_reports');
        $capabilities['enrollment_review'] = in_array($c['binding']->role,['admin','superadmin'],true) && (bool)config('mapa_mobile.enrollment_enabled',false) && Schema::hasTable('mapa_mobile_enrollments');
        $capabilities['self_registration'] = (bool)config('mapa_mobile.enrollment_enabled',false) && Schema::hasTable('mapa_mobile_enrollments');
        $capabilities['completion_evidence_required'] = (bool)config('mapa_mobile.completion_evidence_enabled',false);
        $capabilities['hospital_handover'] = in_array($c['binding']->role,['crew','hospital'],true) && HospitalHandoverService::available();
        $capabilities['push_alerts'] = (new PushService)->eligible($c) && (new PushService)->availableFor($c);
        return ['contract'=>Policy::CONTRACT,'user'=>['id'=>$c['user']->public_id,'name'=>$c['user']->name,
            'role'=>$c['binding']->role,'company_id'=>$c['company'],'driver_id'=>$c['driver']?->public_id,
            'vehicle_id'=>$c['vehicle']?->public_id,'place_id'=>$c['place']?->public_id],
            'capabilities'=>$capabilities];
    }
    public function snapshot(array $c): array
    {
        $role = $c['binding']->role;
        $orders = collect();
        if (in_array($role, ['crew', 'admin', 'superadmin'], true)) {
            $query = $this->native(Order::class,$c['company'])->whereNotIn('status',['completed','canceled','cancelled','failed']);
            if ($role === 'crew') $query->where('driver_assigned_uuid',$c['driver']->uuid);
            $orders = $query->orderByDesc('updated_at')->limit(100)->get()->map(fn($o)=>$this->orderView($o,$c));
        }
        $v = $c['vehicle']; $d = $c['driver'];
        return [...$this->capabilities($c),'driver'=>$d ? ['id'=>$d->public_id,'name'=>$c['user']->name,'online'=>(bool)$d->online,'vehicle_id'=>$v?->public_id] : null,
            'vehicle'=>$v ? ['id'=>$v->public_id,'name'=>$v->name,'registration'=>$v->plate_number,
                'locations'=>(new ConnectedTrackingService)->vehicleLocationSources($c['company'],$v,$d?->uuid),
                'latitude'=>$v->location?->getLat(),'longitude'=>$v->location?->getLng()] : null,
            'orders'=>$orders->values()->all(),'places'=>$c['place'] ? [$this->placeView($c['place'])] : [],
            'dispatch_offers'=>class_exists(\App\MapaVoice\AutomaticDispatch::class)?(new \App\MapaVoice\AutomaticDispatch)->offersForContext($c):[],
            'travel_evidence'=>$role === 'crew' && $v ? (new TravelEvidence)->history($c['company'],$d->public_id,$v->public_id,$c['user']->uuid) : null,
            'server_time'=>(int)round(microtime(true)*1000)];
    }
    protected function placeView($place): ?array
    {
        return $place ? ['id'=>$place->public_id,'name'=>$place->name,'address'=>$place->address ?? $place->street1 ?? '',
            'latitude'=>$place->location?->getLat(),'longitude'=>$place->location?->getLng()] : null;
    }
    protected function payload(Order $order, string $company)
    {
        $payload = $order->payload;
        return $payload && $payload->company_uuid === $company && !$payload->deleted_at ? $payload : null;
    }
    protected function signature(Order $order): string
    {
        return hash('sha256',json_encode(Policy::canonical($order->getAttributes()),JSON_THROW_ON_ERROR));
    }
    protected function state(Order $order)
    {
        return DB::table('mapa_mobile_order_states')->where(['order_uuid'=>$order->uuid,'company_uuid'=>$order->company_uuid])->first();
    }
    protected function acknowledged(Order $order): bool
    {
        $state = $this->state($order);
        return $state && $state->driver_uuid === $order->driver_assigned_uuid && hash_equals($state->assignment_hash,$this->signature($order));
    }
    protected function revision(Order $order): string
    {
        $config = $order->orderConfig;
        $state = $this->state($order);
        $tracking = $order->trackingStatuses()->orderByDesc('created_at')->orderByDesc('uuid')->first();
        return hash('sha256',json_encode([$this->signature($order),$config?->getAttributes(),$order->payload?->getAttributes(),$tracking?->getAttributes(),$state],JSON_THROW_ON_ERROR));
    }
    protected function next(Order $order, array $c): \Illuminate\Support\Collection
    {
        if ($c['binding']->role!=='crew' || !$c['vehicle'] || !$this->acknowledged($order)
            || !Policy::canAcknowledge($c['company'],$c['driver']->uuid,$c['vehicle']->uuid,$order->getAttributes())) return collect();
        return collect((new TripWorkflow)->next($order,$c));
    }
    protected function orderView(Order $order, array $c): array
    {
        $payload = $this->payload($order,$c['company']);
        $place = function ($p) use ($c) { return $p && $p->company_uuid === $c['company'] && !$p->deleted_at ? $this->placeView($p) : null; };
        return ['id'=>$order->public_id,'internal_id'=>$order->internal_id,'status'=>$order->status,
            'revision'=>$this->revision($order),'acknowledged'=>$this->acknowledged($order),
            'can_acknowledge'=>$c['binding']->role === 'crew' && Policy::canAcknowledge($c['company'],$c['driver']->uuid,$c['vehicle']?->uuid,$order->getAttributes()),
            'pickup'=>$place($payload?->pickup),'dropoff'=>$place($payload?->dropoff),
            'tracking'=>TripTrackingView::forOrder($order->uuid,$c['company'],$order),
            'contacts'=>TripContacts::forOrder($order,$c['company'],$payload?->dropoff),
            'dispatch_details'=>CallerPhone::dispatchDetails($order->meta),
            'activity_unavailable_reason'=>(new TripWorkflow)->reason($order,$c),
            'next_arrivals'=>class_exists(TripArrivalService::class)?(new TripArrivalService)->nextArrivals($order,$c):[],
            'next_activities'=>$this->next($order,$c)->all()];
    }
    public function logout(array $c): array
    {
        DB::table('mapa_mobile_sessions')->where('token_hash',$c['token_hash'])->update(['revoked_at'=>(int)round(microtime(true)*1000)]);
        return ['ok'=>true];
    }
    public function mutate(Request $request, string $operation, ?string $id = null): array
    {
        if ($operation === 'activity' && !config('mapa_mobile.trips_enabled',false)) self::fail('Mobile trip updates are not enabled. Continue in the console.',409);
        $body = $request->json()->all();
        if (!Policy::validAction($body['action_id']??null)) self::fail('A unique action_id is required.',422);
        return DB::transaction(function () use ($request,$operation,$id,$body) {
            $c = $this->context($request,true);
            $c['private_media']=$this->privateMedia;
            if ($c['binding']->role !== 'crew') self::fail('This action requires the assigned crew.');
            $order = null;
            if ($id !== null) {
                if (!$c['vehicle']) self::fail('A native vehicle assignment is required.',409);
                $order = $this->native(Order::class,$c['company'])->where('public_id',$id)->lockForUpdate()->first();
                if (!$order || !Policy::ownsOrder($c['company'],$c['driver']->uuid,$order->getAttributes())) self::fail('Assigned order not found.',404);
                if ($order->vehicle_assigned_uuid && $order->vehicle_assigned_uuid !== $c['vehicle']?->uuid) self::fail('Order vehicle assignment changed. Ask the dispatcher to review.',409);
                $order->setRelation('payload',$this->native(Payload::class,$c['company'])->where('uuid',$order->payload_uuid)->lockForUpdate()->first());
                $order->setRelation('orderConfig',$this->native(OrderConfig::class,$c['company'])->where('uuid',$order->order_config_uuid)->lockForUpdate()->first());
            }
            if (in_array($operation,['gps','duty'],true) && !$c['vehicle']) self::fail('A native vehicle assignment is required.',409);
            $receiptKey = ['company_uuid'=>$c['company'],'user_uuid'=>$c['user']->uuid,'action_id'=>$body['action_id']];
            // A fresh login after reassignment must not replay a receipt for
            // the old vehicle/role; same-scope reauthentication may retry safely.
            $hash = Policy::fingerprint($this->scopeHash($c).':'.$operation.':'.$id,$body);
            $receipt = DB::table('mapa_mobile_receipts')->where($receiptKey)->first();
            if ($receipt) {
                if (!hash_equals($receipt->request_hash,$hash)) self::fail('action_id was already used with different details.',409);
                $saved=json_decode($receipt->result,true,512,JSON_THROW_ON_ERROR);
                unset($saved['_session_hash']);
                return [...$saved,'replayed'=>true];
            }
            if ($order) {
                if (!Policy::canAcknowledge($c['company'],$c['driver']->uuid,$c['vehicle']?->uuid,$order->getAttributes())) self::fail('Order is not an active dispatch.',409);
                if (!is_string($body['expected_revision']??null) || !hash_equals($this->revision($order),$body['expected_revision'])) self::fail('Order changed. Refresh and review.',409);
            }
            $result = ['ok'=>true,'replayed'=>false];
            if ($operation === 'duty') {
                if (!is_bool($body['online']??null)) self::fail('online must be a boolean.',422);
                if (!$body['online'] && $this->native(Order::class,$c['company'])->where('driver_assigned_uuid',$c['driver']->uuid)->where('dispatched',true)->whereNotIn('status',['completed','canceled','cancelled','failed'])->exists()) self::fail('Resolve the assigned dispatch before ending duty.',409);
                $c['driver']->updateQuietly(['online'=>$body['online']]); $c['vehicle']->updateQuietly(['online'=>$body['online']]);
            } elseif ($operation === 'gps') {
                if (!$c['driver']->online) self::fail('Start duty before sharing GPS.',409);
                if (array_key_exists('mock_location',$body) && $body['mock_location'] !== false)
                    self::fail('Mock or invalid location reports cannot update crew GPS.',422);
                $last = DB::table('mapa_mobile_driver_states')->where('driver_uuid',$c['driver']->uuid)->lockForUpdate()->first();
                if (!Policy::freshFix($body,(int)round(microtime(true)*1000),(int)($last->recorded_at??0))) self::fail('GPS fix is invalid, stale, inaccurate or out of order.',422);
                foreach (['heading'=>360,'speed_mps'=>90] as $telemetryKey=>$max) if (isset($body[$telemetryKey]) && (!is_numeric($body[$telemetryKey]) || !is_finite((float)$body[$telemetryKey]) || $body[$telemetryKey]<0 || $body[$telemetryKey]>=$max)) self::fail('Invalid GPS telemetry.',422);
                $position = ['location'=>new Point((float)$body['latitude'],(float)$body['longitude']),
                    'latitude'=>(float)$body['latitude'],'longitude'=>(float)$body['longitude'],'heading'=>$body['heading']??null];
                // Native speed unit is unspecified; do not write a guessed conversion.
                $c['driver']->updateQuietly($position); $c['driver']->createPosition($position);
                $vehicleProjected=VehiclePositionProjection::apply($c['vehicle'],$position,(int)$body['recorded_at'],'crew_phone',$c['driver']->uuid);
                $result['map_updated']=$vehicleProjected;
                DB::table('mapa_mobile_driver_states')->updateOrInsert(['driver_uuid'=>$c['driver']->uuid],['company_uuid'=>$c['company'],'recorded_at'=>(int)$body['recorded_at'],'created_at'=>now(),'updated_at'=>now()]);
                // The immutable action receipt stores device and receipt times separately.
                // This is device-reported evidence, not attestation of a person or trip.
                $result['gps_evidence']=['driver_id'=>$c['driver']->public_id,'vehicle_id'=>$c['vehicle']->public_id,
                    'latitude'=>(float)$body['latitude'],'longitude'=>(float)$body['longitude'],
                    'recorded_at'=>(int)$body['recorded_at'],'received_at'=>(int)round(microtime(true)*1000),
                    'accuracy_m'=>(float)$body['accuracy_m'],'source'=>'device_gps','mock_location'=>$body['mock_location']??null,
                    'simulated'=>(bool)(data_get($c['driver']->meta,'simulated',false)||data_get($c['vehicle']->meta,'simulated',false)),
                    'demo'=>(bool)(data_get($c['driver']->meta,'mapa_demo',false)||data_get($c['vehicle']->meta,'mapa_demo',false))];
                $this->publishLocationAfterCommit($c,$vehicleProjected);
            } elseif ($operation === 'ack') {
                $this->acknowledge($order,$c);
                $result['revision'] = $this->revision($order);
            } elseif ($operation === 'activity') {
                if (!$this->acknowledged($order)) self::fail('Review and accept the current call before updating its trip.',409);
                $result += (new TripWorkflow)->apply($order,$c,$body);
                DB::table('mapa_mobile_order_states')->where(['order_uuid'=>$order->uuid,'company_uuid'=>$c['company']])->update(['assignment_hash'=>$this->signature($order),'updated_at'=>now()]);
                $result['revision']=$this->revision($order);
            } else self::fail('Unsupported mobile operation.',404);
            DB::table('mapa_mobile_receipts')->insert([...$receiptKey,'request_hash'=>$hash,'result'=>json_encode([...$result,'_session_hash'=>$c['token_hash']],JSON_THROW_ON_ERROR),'created_at'=>now()]);
            return $result;
        });
    }
    /** Broadcast only after the native fix and immutable receipt have committed.
     * Queue/socket failures must not turn a persisted fix into a failed upload.
     * Driver and vehicle dispatches are independent so one failure cannot skip both.
     */
    protected function publishLocationAfterCommit(array $c,bool $vehicleProjected=true): void
    {
        DB::afterCommit(function () use ($c,$vehicleProjected) {
            $events=[
                'driver.location_changed'=>fn()=>new CrewDriverLocationChanged($c['driver'],$c['company']),
            ];
            if($vehicleProjected)$events['vehicle.location_changed']=fn()=>new CrewVehicleLocationChanged($c['vehicle'],$c['company'],['driver'=>$c['driver']->public_id]);
            foreach($events as $name=>$create) {
                try { event($create()); }
                catch (\Throwable $e) {
                    try { \Illuminate\Support\Facades\Log::warning('MAPA mobile location broadcast failed.',[
                        'company_uuid'=>$c['company'],'driver_uuid'=>$c['driver']->uuid,'vehicle_uuid'=>$c['vehicle']->uuid,
                        'event'=>$name,'exception_class'=>get_class($e),
                    ]); } catch (\Throwable $logFailure) { /* Persistence already committed, including its retry receipt. */ }
                }
            }
        });
    }
    protected function acknowledge(Order $order, array $c): void
    {
        $acknowledgedAt = (int) round(microtime(true)*1000);
        $meta = json_decode($order->getRawOriginal('meta') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($meta)) self::fail('Order metadata requires console review.',409);
        // Visible with the native order metadata, without changing status or
        // inventing a tracking transition. Preserve all unrelated metadata.
        $meta['mapa_mobile_acknowledgement'] = ['acknowledged_at'=>$acknowledgedAt,
            'driver_id'=>$c['driver']->public_id,'vehicle_id'=>$c['vehicle']->public_id,
            'user_id'=>$c['user']->public_id];
        $order->setAttribute('meta', $meta);
        $order->saveQuietly();
        // MySQL normalizes JSON on persistence; hash the reread native row so
        // the acknowledgement remains valid in the next independent request.
        $order->refresh();
        DB::table('mapa_mobile_order_states')->updateOrInsert(['order_uuid'=>$order->uuid],['company_uuid'=>$c['company'],
            'driver_uuid'=>$c['driver']->uuid,'acknowledged_by'=>$c['user']->uuid,'acknowledged_at'=>$acknowledgedAt,
            'assignment_hash'=>$this->signature($order),'created_at'=>now(),'updated_at'=>now()]);
    }
}
