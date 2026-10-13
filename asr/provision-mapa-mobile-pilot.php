<?php
/** MAPA.DEV: explicit local pilot fixtures. No models are saved and no events are sent. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Fleetbase\FleetOps\Models\Order;

/** Input is private JSON, never command-line passwords. Existing records are verified, never reset. */
function mapaProvisionMobilePilot(array $input, bool $apply): array
{
    $dataset = 'mapa-mobile-pilot-v1';
    if (!app()->environment('local', 'testing') || !app()->runningInConsole()) {
        throw new RuntimeException('Pilot provisioning requires a local/testing CLI process.');
    }
    $company = (string) config('mapa.company_uuid');
    if (($input['dataset'] ?? null) !== $dataset || ($input['company_uuid'] ?? null) !== $company
        || !preg_match('/^[a-f0-9-]{36}$/iD', $company)
        || !DB::table('companies')->where('uuid', $company)->whereNull('deleted_at')->exists()) {
        throw new RuntimeException('Input dataset and company must match the configured MAPA company.');
    }
    foreach (['mapa_mobile_bindings', 'mapa_mobile_sessions'] as $table) {
        try { DB::table($table)->limit(0)->get(); }
        catch (Throwable $error) { throw new RuntimeException('The reviewed mobile migration must be installed first.'); }
    }
    $accounts = $input['accounts'] ?? [];
    foreach (['crew', 'hospital', 'admin'] as $role) {
        if (($accounts[$role]['email'] ?? '') !== $role . '.demo@mapa.invalid'
            || !is_string($accounts[$role]['password'] ?? null)
            || strlen($accounts[$role]['password']) < 24 || strlen($accounts[$role]['password']) > 200) {
            throw new RuntimeException('Supply all three fixed .invalid pilot emails and independently generated passwords of 24–200 bytes.');
        }
    }
    if (count(array_unique(array_column($accounts, 'password'))) < 3) {
        throw new RuntimeException('Pilot account passwords must be distinct.');
    }
    $id = static function (string $key) use ($company, $dataset): string {
        $h = hash('sha256', "$dataset:$company:$key");
        return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-a'.substr($h,17,3).'-'.substr($h,20,12);
    };
    $meta = json_encode(['mapa_demo'=>true, 'simulated'=>true, 'demo_dataset'=>$dataset,
        'notice'=>'Fictional local mobile pilot. No real patient, person, ambulance, facility or dispatch.',
        'verification'=>'Offline provisioning of fictional .invalid pilot identity only; no email was sent.'], JSON_THROW_ON_ERROR);
    $point = static fn(float $lat, float $lng) => DB::raw("ST_GeomFromText('POINT($lng $lat)', 0)");
    $created = $unchanged = 0;
    $now = now()->toDateTimeString();
    return DB::transaction(function () use ($input,$accounts,$apply,$dataset,$company,$id,$meta,$point,$now,&$created,&$unchanged) {
        $put = static function (string $table, string $key, array $values, array $fixed = []) use ($apply,$company,$dataset,$id,$meta,$now,&$created,&$unchanged): string {
            $uuid = $id($key);
            $row = DB::table($table)->where('uuid', $uuid)->lockForUpdate()->first();
            if ($row) {
                if ($row->company_uuid !== $company || $row->_key !== $dataset || $row->deleted_at !== null) {
                    throw new RuntimeException('Refusing an unowned, reassigned or retired pilot record.');
                }
                if (property_exists($row, 'meta')) {
                    $m = json_decode($row->meta ?? '{}', true);
                    if (($m['mapa_demo'] ?? null) !== true || ($m['demo_dataset'] ?? '') !== $dataset) {
                        throw new RuntimeException('Pilot metadata ownership check failed.');
                    }
                }
                foreach ($fixed as $field) {
                    if ((string)($row->$field ?? '') !== (string)($values[$field] ?? '')) {
                        throw new RuntimeException('Pilot identity, assignment or workflow changed; review it manually.');
                    }
                }
                $unchanged++;
            } else {
                if (!$apply) throw new RuntimeException('Pilot fixture missing; explicit --apply is required to create it.');
                if (isset($values['public_id']) && DB::table($table)->where('public_id',$values['public_id'])->exists()) {
                    throw new RuntimeException('Pilot public ID already belongs to another record.');
                }
                $base = ['uuid'=>$uuid,'company_uuid'=>$company,'_key'=>$dataset,'created_at'=>$now,'updated_at'=>$now];
                if (Schema::hasColumn($table, 'meta')) $base['meta'] = $meta;
                DB::table($table)->insert([...$base,...$values]);
                $created++;
            }
            return $uuid;
        };
        $users = [];
        foreach (['crew','hospital','admin'] as $role) {
            $expected = $id('user-'.$role);
            $email = $accounts[$role]['email'];
            if (DB::table('users')->where('email',$email)->where('uuid','<>',$expected)->exists()) {
                throw new RuntimeException('A pilot email is already attached to another identity.');
            }
            $users[$role] = $put('users','user-'.$role,[
                'public_id'=>'user_mapa_mobile_demo_'.$role,'name'=>'DEMO Mobile '.ucfirst($role),
                'email'=>$email,'phone'=>null,'password'=>Hash::make($accounts[$role]['password']),
                'status'=>'active','type'=>$role === 'crew' ? 'driver' : 'user',
                'email_verified_at'=>$now,'country'=>'IN','timezone'=>'Asia/Kolkata',
            ],['public_id','name','email','type','status']);
            $user = DB::table('users')->where('uuid',$users[$role])->first();
            if (!$user->email_verified_at || !Hash::check($accounts[$role]['password'], $user->password)) {
                throw new RuntimeException('Existing pilot credentials or verification differ; no password reset was performed.');
            }
            if (DB::table('company_users')->where('company_uuid',$company)->where('user_uuid',$users[$role])
                ->where('uuid','<>',$id('membership-'.$role))->exists()) {
                throw new RuntimeException('Pilot membership already exists under another identity.');
            }
            $put('company_users','membership-'.$role,['user_uuid'=>$users[$role],'status'=>'active','external'=>0],['user_uuid','status']);
        }
        $vehicle = $put('vehicles','vehicle',[
            'public_id'=>'vehicle_mapa_mobile_demo_01','internal_id'=>'DEMO-MOBILE-AMB-01',
            'name'=>'DEMO Mobile Ambulance — Paderu','description'=>'Fictional MAPA Android pilot ambulance.',
            'plate_number'=>'DEMO-MOBILE-01','call_sign'=>'DEMO-MOBILE-01','status'=>'active','online'=>0,
            'location'=>$point(18.083,82.669),'slug'=>'mapa-mobile-demo-ambulance',
        ],['public_id','internal_id','plate_number']);
        $driver = $put('drivers','driver',[
            'public_id'=>'driver_mapa_mobile_demo_01','internal_id'=>'DEMO-MOBILE-CREW-01',
            'user_uuid'=>$users['crew'],'vehicle_uuid'=>$vehicle,'location'=>$point(18.083,82.669),
            'latitude'=>18.083,'longitude'=>82.669,'country'=>'IN','city'=>'Paderu','currency'=>'INR',
            'online'=>0,'status'=>'available','current_status'=>'DEMO mobile pilot — simulated',
            'slug'=>'mapa-mobile-demo-crew',
        ],['public_id','user_uuid','vehicle_uuid']);
        if (DB::table('drivers')->where('vehicle_uuid',$vehicle)->where('uuid','<>',$driver)->whereNull('deleted_at')->exists()) {
            throw new RuntimeException('Pilot ambulance is assigned to another driver.');
        }
        $places = [];
        foreach (['pickup'=>[18.088,82.674,'DEMO Mobile Pickup — Paderu','depot'],
                  'hospital'=>[18.093,82.679,'DEMO Mobile Receiving Hospital — Paderu','hospital']] as $key=>[$lat,$lng,$name,$type]) {
            $places[$key] = $put('places','place-'.$key,[
                'public_id'=>'place_mapa_mobile_demo_'.$key,'name'=>$name,
                'street1'=>'Fictional presentation location; no actual facility or patient',
                'city'=>'Paderu','district'=>'Alluri Sitharama Raju','province'=>'Andhra Pradesh','country'=>'IN',
                'type'=>$type,'location'=>$point($lat,$lng),'latitude'=>$lat,'longitude'=>$lng,
            ],['public_id','name']);
        }
        $config = DB::table('order_configs')->where('company_uuid',$company)->where('key','transport')->whereNull('deleted_at')->lockForUpdate()->first();
        $flow = json_decode($config->flow ?? '{}',true);
        if (!$config || !isset($flow['dispatched']) || ($flow['dispatched']['complete'] ?? true)) {
            throw new RuntimeException('A reviewed native transport configuration with a dispatched stage is required.');
        }
        $payload = $put('payloads','payload',[
            'public_id'=>'payload_mapa_mobile_demo_01','pickup_uuid'=>$places['pickup'],'dropoff_uuid'=>$places['hospital'],'type'=>'transport',
        ],['public_id','pickup_uuid','dropoff_uuid']);
        $trackingExisted = DB::table('tracking_numbers')->where('uuid',$id('tracking'))->exists();
        $tracking = $put('tracking_numbers','tracking',[
            'public_id'=>'tracking_mapa_mobile_demo_01','tracking_number'=>'DEMO-MAPA-MOBILE-01',
            'owner_uuid'=>$id('order'),'owner_type'=>(new Order)->getMorphClass(),'region'=>'IN',
            // Native FK is circular: status belongs to tracking number and the
            // tracking number points at its current status. Link after both exist.
            'status_uuid'=>null,
        ],['public_id','tracking_number','owner_uuid','owner_type']);
        $put('tracking_statuses','tracking-status',[
            'public_id'=>'status_mapa_mobile_demo_01','tracking_number_uuid'=>$tracking,
            'status'=>'DEMO — Ambulance dispatched','details'=>'Fictional mobile pilot only. No real dispatch was sent.',
            'code'=>'dispatched','complete'=>0,'city'=>'Paderu','province'=>'Andhra Pradesh','country'=>'IN',
            'location'=>$point(18.083,82.669),'latitude'=>18.083,'longitude'=>82.669,
        ],['public_id','tracking_number_uuid','code']);
        if (!$trackingExisted && $apply) {
            DB::table('tracking_numbers')->where('uuid',$tracking)->where('company_uuid',$company)->where('_key',$dataset)
                ->whereNull('status_uuid')->update(['status_uuid'=>$id('tracking-status')]);
        } elseif (DB::table('tracking_numbers')->where('uuid',$tracking)->value('status_uuid') !== $id('tracking-status')) {
            throw new RuntimeException('Pilot tracking status changed; no existing status pointer was reset.');
        }
        $order = $put('orders','order',[
            'public_id'=>'order_mapa_mobile_demo_01','internal_id'=>'DEMO-MOBILE-CALL-01',
            'created_by_uuid'=>$users['admin'],'order_config_uuid'=>$config->uuid,'payload_uuid'=>$payload,
            'tracking_number_uuid'=>$tracking,'driver_assigned_uuid'=>$driver,'vehicle_assigned_uuid'=>$vehicle,
            'type'=>'transport','status'=>'dispatched','dispatched'=>1,'dispatched_at'=>$now,'started'=>0,'pod_required'=>0,
            'notes'=>'DEMO fictional transport. No real patient. Supervised Android connection test only.',
        ],['public_id','internal_id','order_config_uuid','payload_uuid','tracking_number_uuid','driver_assigned_uuid','vehicle_assigned_uuid','status','dispatched']);
        $bind = static function (string $role, string $user, ?string $driver, ?string $place) use ($apply,$company,$id,$now,&$created,&$unchanged) {
            $key = $id('binding-'.$role);
            $expected = ['id'=>$key,'company_uuid'=>$company,'user_uuid'=>$user,'role'=>$role,'driver_uuid'=>$driver,'place_uuid'=>$place,'enabled'=>1,'revision'=>1];
            $row = DB::table('mapa_mobile_bindings')->where('company_uuid',$company)->where('user_uuid',$user)->lockForUpdate()->first();
            if ($row) {
                foreach ($expected as $field=>$value) if ((string)($row->$field ?? '') !== (string)($value ?? '')) {
                    throw new RuntimeException('An existing mobile binding differs; no binding was changed.');
                }
                $unchanged++;
            } else {
                if (!$apply) throw new RuntimeException('Mobile binding is missing.');
                DB::table('mapa_mobile_bindings')->insert([...$expected,'created_at'=>$now,'updated_at'=>$now]);
                $created++;
            }
        };
        $bind('crew',$users['crew'],$driver,null);
        $bind('hospital',$users['hospital'],null,$places['hospital']);
        $bind('admin',$users['admin'],null,null);
        $superadmin = $input['superadmin_user_uuid'] ?? null;
        if ($superadmin !== null) {
            $authorized = DB::table('users')->where('uuid',$superadmin)->where('type','admin')->where('status','active')->whereNull('deleted_at')->exists()
                && DB::table('company_users')->where('company_uuid',$company)->where('user_uuid',$superadmin)->where('status','active')->whereNull('deleted_at')->exists()
                && in_array($superadmin, config('mapa_mobile.superadmin_user_uuids',[]),true);
            if (!$authorized) throw new RuntimeException('Superadmin must already be a native platform admin with active membership and explicit server allowlisting.');
            $bind('superadmin',$superadmin,null,null);
        }
        // Eloquent reads prove the console and mobile API resolve these native references.
        $native = Order::withoutGlobalScopes()->where('company_uuid',$company)->where('uuid',$order)->firstOrFail();
        if ($native->payload?->pickup?->uuid !== $places['pickup'] || $native->payload?->dropoff?->uuid !== $places['hospital']
            || $native->trackingNumber?->uuid !== $tracking || $native->orderConfig?->uuid !== $config->uuid
            || !$native->trackingStatuses()->where('uuid',$id('tracking-status'))->exists()) {
            throw new RuntimeException('Native order/payload/tracking read-back failed.');
        }
        return ['dataset'=>$dataset,'company_uuid'=>$company,'simulated'=>true,'mode'=>$apply?'apply':'verify',
            'created'=>$created,'unchanged'=>$unchanged,'roles'=>$superadmin?['crew','hospital','admin','superadmin']:['crew','hospital','admin'],
            'order_id'=>'order_mapa_mobile_demo_01','vehicle_id'=>'vehicle_mapa_mobile_demo_01',
            'driver_id'=>'driver_mapa_mobile_demo_01','hospital_id'=>'place_mapa_mobile_demo_hospital',
            'notice'=>'No credentials, invitations, notifications or patient records are included in this output.'];
    });
}

// A test harness may load this function and create connection-local temporary schema shadows.
if (defined('MAPA_MOBILE_PILOT_LIBRARY')) return;
try {
    if (count($argv) !== 2 || !in_array($argv[1], ['--apply','--verify'],true)) {
        throw new RuntimeException('Usage: php provision-mapa-mobile-pilot.php --apply|--verify < private-pilot.json');
    }
    $raw = stream_get_contents(STDIN, 16385);
    if (strlen($raw) > 16384) throw new RuntimeException('Private input is too large.');
    $input = json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new RuntimeException('Private input must be a JSON object.');
    echo json_encode(mapaProvisionMobilePilot($input,$argv[1] === '--apply'),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch (Throwable $error) {
    // Query exception messages can contain parameter values. Never log or print them.
    $message = get_class($error) === RuntimeException::class ? $error->getMessage() : 'Pilot operation failed; transaction rolled back. Review schema/configuration without logging private input.';
    $diagnostic = ['error_class'=>get_class($error)];
    if ($error instanceof Illuminate\Database\QueryException || $error instanceof PDOException) {
        $info = $error->errorInfo ?? [];
        if (is_string($info[0] ?? null) && preg_match('/^[A-Z0-9]{5}$/D',$info[0])) $diagnostic['sql_state'] = $info[0];
        if (isset($info[1]) && ctype_digit((string)$info[1])) $diagnostic['driver_code'] = (int)$info[1];
    }
    fwrite(STDERR,$message.PHP_EOL.json_encode($diagnostic,JSON_THROW_ON_ERROR).PHP_EOL); exit(1);
}
