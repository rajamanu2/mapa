<?php
namespace App\MapaMobile;
use Fleetbase\Models\User;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Role;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Pending identity collection is separate from operator authorization. */
class EnrollmentService extends MobileService
{
    protected function ready(): string {
        $company=$this->company();
        if(!config('mapa_mobile.enrollment_enabled',false) || !Schema::hasTable('mapa_mobile_enrollments') || !Schema::hasTable('mapa_mobile_private_media')) self::fail('Crew registration is awaiting command-center setup.',503);
        return $company;
    }
    protected function staff(Request $r,bool $lock=false): array {
        $this->ready(); $c=$this->context($r,$lock);
        if(!in_array($c['binding']->role,['admin','superadmin'],true)) self::fail('A command-center administrator is required.');
        return $c;
    }
    private function epoch(): int { return (int)round(microtime(true)*1000); }
    private function tokenRow(Request $r,bool $lock=false,bool $terminalStatus=false): object {
        $company=$this->ready(); $secret=$r->bearerToken();
        if(!EnrollmentPolicy::token($secret)) self::fail('This phone is not connected. Register or contact control.',401);
        $q=DB::table('mapa_mobile_enrollments')->where(['company_uuid'=>$company,'connect_hash'=>hash('sha256',$secret)]);
        $e=($lock?$q->lockForUpdate():$q)->first();
        if(!$e || (!$terminalStatus && ($e->revoked_at!==null || $e->connect_expires_at<=$this->epoch()))) self::fail('Phone connection expired or was revoked. Contact control.',401);
        return $e;
    }
    private function pendingView(object $e): array {
        return ['contract'=>Policy::CONTRACT,'ok'=>true,'enrollment_id'=>$e->id,'status'=>$e->status,'revision'=>(int)$e->revision,
            'connect_expires_at'=>(int)$e->connect_expires_at];
    }
    private function adminView(object $e, ?array $roster=null): array {
        $driver=$e->driver_uuid ? $this->native(Driver::class,$e->company_uuid)->where('uuid',$e->driver_uuid)->first() : null;
        $vehicle=$e->vehicle_uuid ? $this->native(Vehicle::class,$e->company_uuid)->where('uuid',$e->vehicle_uuid)->first() : null;
        $place=$e->place_uuid ? $this->native(Place::class,$e->company_uuid)->where('uuid',$e->place_uuid)->first() : null;
        return ['id'=>$e->id,'status'=>$e->status,'revision'=>(int)$e->revision,'name'=>$e->name,'email'=>$e->email,'phone'=>$e->phone,
            'ambulance_registration'=>$e->ambulance_registration,'ambulance_name'=>$e->ambulance_name,'hospital_name'=>$e->hospital_name,
            'created_at'=>$e->created_at,'approved_at'=>$e->approved_at,'driver_id'=>$driver?->public_id,'vehicle_id'=>$vehicle?->public_id,'place_id'=>$place?->public_id,
            'locations'=>$driver && $vehicle && $driver->vehicle_uuid===$vehicle->uuid?(new ConnectedTrackingService)->vehicleLocationSources($e->company_uuid,$vehicle,$driver->uuid):null,
            'selfie_media_id'=>$e->selfie_media_id,'latitude'=>(float)$e->latitude,'longitude'=>(float)$e->longitude,'accuracy_m'=>(float)$e->accuracy_m,
            'recorded_at'=>(int)$e->recorded_at,'selfie_captured_at'=>(int)$e->selfie_captured_at,'review_reason'=>$e->review_reason,
            'crew_matches'=>$e->status==='pending'?$this->crewMatches($e,$roster):[]];
    }
    /** Suggestions stay company-scoped; a name is never an identity proof. */
    private function crewRoster(string $company): array {
        $drivers=$this->native(Driver::class,$company)->get();
        $users=User::where('company_uuid',$company)->whereIn('uuid',$drivers->pluck('user_uuid'))->get()->keyBy('uuid');
        $roster=[];
        foreach($drivers as $driver) if($user=$users->get($driver->user_uuid)) $roster[]=[$driver,$user];
        return $roster;
    }
    private function crewMatches(object $e, ?array $roster=null): array {
        $matches=[];
        foreach($roster??$this->crewRoster($e->company_uuid) as [$driver,$user]) {
            $reasons=[];
            if(self::normalizedText((string)$user->name)===self::normalizedText($e->name)) $reasons[]='name';
            try { if(CallerPhone::normalize((string)$user->phone)===$e->phone) $reasons[]='phone'; } catch(\InvalidArgumentException $ignored) {}
            if(strtolower(trim((string)$user->email))===$e->email) $reasons[]='email';
            if(!$reasons) continue;
            $matches[]=['id'=>$driver->public_id,'name'=>$user->name,'phone'=>$user->phone,'matched_on'=>$reasons];
        }
        return $matches;
    }
    public function register(Request $r): array {
        $company=$this->ready(); $body=$r->json()->all();
        $v=$r->validate(['action_id'=>'required|string|min:8|max:100','connect_token'=>'required|string|size:64',
            'name'=>'required|string|max:150','email'=>'required|email|max:200','phone'=>'required|string|max:40',
            'ambulance_registration'=>'required|string|max:50','ambulance_name'=>'required|string|max:150','hospital_name'=>'required|string|max:200',
            'device_name'=>'required|string|max:100','consent'=>'required|accepted','selfie_base64'=>'required|string',
            'selfie_captured_at'=>'required|integer','latitude'=>'required|numeric','longitude'=>'required|numeric','recorded_at'=>'required|integer','accuracy_m'=>'required|numeric','mock_location'=>'required|boolean']);
        if(!Policy::validAction($v['action_id']) || !EnrollmentPolicy::token($v['connect_token']) || ($body['consent']??null)!==true) self::fail('Invalid registration or consent.',422);
        foreach(['name','ambulance_registration','ambulance_name','hospital_name','device_name'] as $key) if(trim($v[$key])==='') self::fail('Complete the crew registration details.',422);
        $hash=Policy::fingerprint('crew-enrollment:'.$company,$body);
        return DB::transaction(function()use($company,$v,$body,$hash){
            Company::where('uuid',$company)->lockForUpdate()->firstOrFail();
            $existing=DB::table('mapa_mobile_enrollments')->where(['company_uuid'=>$company,'action_id'=>$v['action_id']])->lockForUpdate()->first();
            if($existing) {
                if(!hash_equals($existing->connect_hash,hash('sha256',$v['connect_token'])) || !hash_equals($existing->request_hash,$hash)) self::fail('Registration action was already used with different details.',409);
                return [...$this->pendingView($existing),'replayed'=>true];
            }
            $now=$this->epoch();
            if(!EnrollmentPolicy::gps($body,$now) || !EnrollmentPolicy::capture($v['selfie_captured_at'],$now) || abs($v['recorded_at']-$v['selfie_captured_at'])>30000)
                self::fail('Capture a new selfie with a fresh accurate non-mock phone location.',422);
            try { $phone=CallerPhone::normalize($v['phone']); } catch(\InvalidArgumentException $e) { self::fail('Enter a valid crew contact phone number.',422); }
            if($phone==='') self::fail('A crew contact number is required.',422);
            $email=strtolower(trim($v['email']));
            // Existing unconnected roster identities may request staff review only.
            // Deleted, foreign and already connected accounts cannot use signup as recovery.
            $account=User::withTrashed()->whereRaw('LOWER(TRIM(email)) = ?',[$email])->first();
            if(($account && ($account->deleted_at || $account->company_uuid!==$company || $account->status!=='active' || $account->type!=='driver'
                    || !$this->native(Driver::class,$company)->where('user_uuid',$account->uuid)->exists()
                    || DB::table('mapa_mobile_bindings')->where('user_uuid',$account->uuid)->exists()))
                || DB::table('mapa_mobile_enrollments')->where(['company_uuid'=>$company,'email'=>$email])->whereIn('status',['pending','approved'])->exists())
                self::fail('This email already has an account or pending registration. Contact control; existing credentials are preserved.',409);
            if(DB::table('mapa_mobile_enrollments')->where('connect_hash',hash('sha256',$v['connect_token']))->exists()) self::fail('This phone connection was already submitted.',409);
            $jpeg=PrivateMedia::jpeg($v['selfie_base64']); $id=(string)Str::uuid();
            $media=$this->privateMedia->put($jpeg,['company_uuid'=>$company,'enrollment_id'=>$id,'kind'=>'selfie']);
            DB::table('mapa_mobile_enrollments')->insert(['id'=>$id,'company_uuid'=>$company,'action_id'=>$v['action_id'],'request_hash'=>$hash,'connect_hash'=>hash('sha256',$v['connect_token']),
                'status'=>'pending','revision'=>1,'name'=>trim($v['name']),'email'=>$email,'phone'=>$phone,
                'ambulance_registration'=>trim($v['ambulance_registration']),'ambulance_name'=>trim($v['ambulance_name']),'hospital_name'=>trim($v['hospital_name']),'device_name'=>trim($v['device_name']),
                'latitude'=>(float)$v['latitude'],'longitude'=>(float)$v['longitude'],'accuracy_m'=>(float)$v['accuracy_m'],'recorded_at'=>$v['recorded_at'],'selfie_captured_at'=>$v['selfie_captured_at'],
                'selfie_media_id'=>$media,'consented_at'=>$now,'connect_expires_at'=>$now+30*86400000,'created_at'=>now(),'updated_at'=>now()]);
            return [...$this->pendingView(DB::table('mapa_mobile_enrollments')->where('id',$id)->first()),'replayed'=>false];
        });
    }
    public function status(Request $r): array {
        // The own opaque secret can learn its terminal connection state, never
        // receive a session, private media or operational rights after revocation.
        $e=$this->tokenRow($r,false,true); $view=$this->pendingView($e);
        if($e->revoked_at===null && $e->connect_expires_at<=$this->epoch()) { $view['status']='revoked'; $view['reason']='expired'; }
        return $view;
    }
    public function connect(Request $r): array {
        return DB::transaction(function()use($r){
            $e=$this->tokenRow($r,true);
            if($e->status!=='approved' || !$e->binding_id || !$e->user_uuid) self::fail('Command-center approval is required before starting duty.',403);
            $binding=DB::table('mapa_mobile_bindings')->where(['id'=>$e->binding_id,'company_uuid'=>$e->company_uuid,'user_uuid'=>$e->user_uuid])->lockForUpdate()->first();
            $user=User::where('uuid',$e->user_uuid)->lockForUpdate()->first();
            if(!$binding || !$user || $binding->role!=='crew' || (int)$binding->revision!==(int)$e->binding_revision
                || !hash_equals((string)$e->credential_hash,hash('sha256',$user->password))) self::fail('Phone connection changed. Contact control.',401);
            $c=$this->resolve($binding,$user,true);
            if(!hash_equals((string)$e->scope_hash,$this->scopeHash($c))) self::fail('Crew or ambulance assignment changed. Contact control.',401);
            return $this->issueSession($c,$e->device_name);
        },3);
    }
    public function disconnect(Request $r): array {
        return DB::transaction(function()use($r){
            $e=$this->tokenRow($r,true);
            if($e->binding_id) DB::table('mapa_mobile_sessions')->where('binding_id',$e->binding_id)->whereNull('revoked_at')->update(['revoked_at'=>$this->epoch()]);
            DB::table('mapa_mobile_enrollments')->where('id',$e->id)->update(['status'=>'revoked','revision'=>$e->revision+1,'revoked_at'=>$this->epoch(),'review_reason'=>'Phone disconnected by its holder.','updated_at'=>now()]);
            return ['contract'=>Policy::CONTRACT,'ok'=>true];
        });
    }
    public function index(Request $r): array {
        $c=$this->staff($r); $rows=DB::table('mapa_mobile_enrollments')->where('company_uuid',$c['company'])->orderByDesc('created_at')->limit(201)->get();
        $roster=$rows->contains('status','pending')?$this->crewRoster($c['company']):[];
        return ['contract'=>Policy::CONTRACT,'enrollments'=>$rows->take(200)->map(fn($e)=>$this->adminView($e,$roster))->all(),'truncated'=>$rows->count()>200,
            'pending_count'=>DB::table('mapa_mobile_enrollments')->where(['company_uuid'=>$c['company'],'status'=>'pending'])->count()];
    }
    public function options(Request $r): array {
        $c=$this->staff($r);
        $vehicles=$this->native(Vehicle::class,$c['company'])->orderBy('plate_number')->limit(501)->get()->filter(fn($v)=>!data_get($v->meta,'mapa_demo',false) && !data_get($v->meta,'simulated',false));
        $places=$this->native(Place::class,$c['company'])->where(function($q){$q->whereNull('_key')->orWhere('_key','<>','mapa-voice-v1');})->orderBy('name')->limit(501)->get()->filter(fn($p)=>!data_get($p->meta,'mapa_demo',false) && !data_get($p->meta,'simulated',false));
        return ['contract'=>Policy::CONTRACT,'vehicles'=>$vehicles->take(500)->map(fn($v)=>['id'=>$v->public_id,'name'=>$v->name,'registration'=>$v->plate_number])->values()->all(),
            'places'=>$places->take(500)->map(fn($p)=>['id'=>$p->public_id,'name'=>$p->name,'address'=>$p->street1??''])->values()->all(),'truncated'=>$vehicles->count()>500 || $places->count()>500];
    }
    private static function normalizedPlate(string $value): string {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/','',$value));
    }
    private static function normalizedText(string $value): string {
        return mb_strtolower(preg_replace('/\s+/u',' ',trim($value)));
    }
    /** Creating native catalog records is distinct from identity approval/duty. */
    public function createResource(Request $r,string $id,string $kind): array {
        $this->staff($r); // Authenticate before disclosing validation or catalog state.
        if(!in_array($kind,['ambulance','hospital'],true)) self::fail('Unsupported resource.',404);
        $body=$r->json()->all();
        $rules=['action_id'=>'required|string|min:8|max:100','expected_revision'=>'required|integer|min:1','name'=>'required|string|max:150'];
        if($kind==='ambulance') $rules+=['registration'=>'required|string|max:50','details_confirmed'=>'required|boolean'];
        else $rules+=['address'=>'required|string|max:250','latitude'=>'required|numeric|between:-90,90','longitude'=>'required|numeric|between:-180,180','location_confirmed'=>'required|boolean'];
        if(array_diff(array_keys($body),array_keys($rules))) self::fail('Only the resource review fields are accepted.',422);
        $v=$r->validate($rules);
        if(!Policy::validAction($v['action_id']) || trim($v['name'])==='' || ($body[$kind==='ambulance'?'details_confirmed':'location_confirmed']??null)!==true)
            self::fail('Confirm the actual ambulance or hospital/base details.',422);
        if($kind==='ambulance' && (self::normalizedPlate($v['registration'])==='' || !preg_match('/^[A-Za-z0-9 .-]+$/D',$v['registration']))) self::fail('Enter the actual ambulance registration.',422);
        if($kind==='hospital' && (trim($v['address'])==='' || !is_finite((float)$v['latitude']) || !is_finite((float)$v['longitude']) || ((float)$v['latitude']===0.0 && (float)$v['longitude']===0.0)))
            self::fail('Enter the verified hospital/base address and map coordinates.',422);
        return DB::transaction(function()use($r,$id,$kind,$body,$v){
            $c=$this->staff($r,true); $company=$c['company'];
            // Serializes normalized catalog collision checks for this creation flow.
            Company::where('uuid',$company)->lockForUpdate()->firstOrFail();
            $e=DB::table('mapa_mobile_enrollments')->where(['id'=>$id,'company_uuid'=>$company])->lockForUpdate()->first();
            if(!$e) self::fail('Enrollment not found.',404);
            $hash=Policy::fingerprint('enrollment-resource:'.$kind.':'.$id,$body);
            $receipt=DB::table('mapa_mobile_enrollment_audit')->where(['company_uuid'=>$company,'enrollment_id'=>$id,'action_id'=>$v['action_id']])->first();
            if($receipt) {
                if(!hash_equals($receipt->request_hash,$hash)) self::fail('Action was already used with different details.',409);
                return [...json_decode($receipt->result,true,512,JSON_THROW_ON_ERROR),'replayed'=>true];
            }
            if($e->revision!=$v['expected_revision'] || $e->status!=='pending' || $e->revoked_at!==null || $e->connect_expires_at<=$this->epoch())
                self::fail('Only a current unchanged pending enrollment can create review resources. Refresh and review.',409);
            $meta=['mapa_demo'=>false,'mapa_enrollment_id'=>$e->id,'created_from'=>'operator_reviewed_crew_registration','details_confirmed_by'=>$c['user']->public_id];
            $uuid=(string)Str::uuid(); $suffix=bin2hex(random_bytes(12));
            if($kind==='ambulance') {
                $plate=self::normalizedPlate($v['registration']);
                if($plate!==self::normalizedPlate($e->ambulance_registration)) self::fail('Ambulance registration does not match the crew form. Review the details.',409);
                // Include soft-deleted records: no duplicate/reactivation or silent takeover.
                $collision=Vehicle::withoutGlobalScopes()->where('company_uuid',$company)->whereRaw("UPPER(REGEXP_REPLACE(COALESCE(plate_number,''),'[^A-Za-z0-9]','')) = ?",[$plate])->lockForUpdate()->first();
                if($collision) self::fail('This ambulance registration already exists. Select its existing record or resolve it in control.',409);
                $model=new Vehicle;
                $model->forceFill(['uuid'=>$uuid,'public_id'=>'vehicle_mapa_'.$suffix,'_key'=>'mapa-crew-review-v1','company_uuid'=>$company,'name'=>trim($v['name']),
                    'plate_number'=>strtoupper(trim($v['registration'])),'type'=>'ambulance','online'=>false,'status'=>'available','meta'=>$meta]);
                // Omit location entirely: the upstream Point setter maps explicit NULL to (0,0).
                // The nullable native DB column must remain unknown until real device GPS arrives.
                $model->saveQuietly();
                $resource=['kind'=>'ambulance','id'=>$model->public_id,'name'=>$model->name,'registration'=>$model->plate_number];
            } else {
                $name=self::normalizedText($v['name']); $address=self::normalizedText($v['address']);
                $existing=Place::withoutGlobalScopes()->where('company_uuid',$company)->where(function($q){$q->whereNull('_key')->orWhere('_key','<>','mapa-voice-v1');})->lockForUpdate()->get(['name','street1']);
                foreach($existing as $place) if(self::normalizedText((string)$place->name)===$name && self::normalizedText((string)$place->street1)===$address)
                    self::fail('This hospital/base name and address already exist. Select its existing record or resolve it in control.',409);
                $model=new Place;
                $model->forceFill(['uuid'=>$uuid,'public_id'=>'place_mapa_'.$suffix,'_key'=>'mapa-crew-review-v1','company_uuid'=>$company,'name'=>trim($v['name']),
                    'street1'=>trim($v['address']),'type'=>'hospital','location'=>new Point((float)$v['latitude'],(float)$v['longitude']),
                    'meta'=>[...$meta,'location_source'=>'operator_confirmed_map_pin','location_confirmed_at'=>$this->epoch()]]);
                $model->saveQuietly();
                $resource=['kind'=>'hospital','id'=>$model->public_id,'name'=>$model->name,'address'=>$model->street1,'latitude'=>(float)$v['latitude'],'longitude'=>(float)$v['longitude']];
            }
            $result=['contract'=>Policy::CONTRACT,'ok'=>true,'enrollment'=>$this->adminView($e),'resource'=>$resource,'replayed'=>false];
            DB::table('mapa_mobile_enrollment_audit')->insert(['company_uuid'=>$company,'enrollment_id'=>$id,'user_uuid'=>$c['user']->uuid,'operation'=>'create_'.$kind,'revision'=>$e->revision,
                'action_id'=>$v['action_id'],'request_hash'=>$hash,'result'=>json_encode($result,JSON_THROW_ON_ERROR),'created_at'=>now()]);
            return $result;
        },3);
    }
    public function review(Request $r,string $id,string $operation): array {
        $v=$r->validate(['action_id'=>'required|string|min:8|max:100','expected_revision'=>'required|integer|min:1','vehicle_id'=>'sometimes|string|max:100','place_id'=>'sometimes|string|max:100','driver_id'=>'sometimes|nullable|string|max:100','create_new_crew'=>'sometimes|boolean','identity_confirmed'=>'sometimes|boolean','reason'=>'sometimes|string|max:500']);
        if(!Policy::validAction($v['action_id'])) self::fail('A unique action_id is required.',422);
        return DB::transaction(function()use($r,$id,$operation,$v){
            $c=$this->staff($r,true); $company=$c['company']; Company::where('uuid',$company)->lockForUpdate()->firstOrFail();
            $e=DB::table('mapa_mobile_enrollments')->where(['id'=>$id,'company_uuid'=>$company])->lockForUpdate()->first(); if(!$e) self::fail('Enrollment not found.',404);
            $hash=Policy::fingerprint('enrollment:'.$operation.':'.$id,$r->json()->all());
            $receipt=DB::table('mapa_mobile_enrollment_audit')->where(['company_uuid'=>$company,'enrollment_id'=>$id,'action_id'=>$v['action_id']])->first();
            if($receipt) { if(!hash_equals($receipt->request_hash,$hash)) self::fail('Action was already used with different details.',409); return [...json_decode($receipt->result,true,512,JSON_THROW_ON_ERROR),'replayed'=>true]; }
            if($e->revision!=$v['expected_revision']) self::fail('Enrollment changed. Refresh and review.',409);
            if($operation==='approve') $this->approve($e,$c,$v);
            elseif($operation==='reject' || $operation==='revoke') {
                if(($operation==='reject' && $e->status!=='pending') || ($operation==='revoke' && $e->status!=='approved')) self::fail('Enrollment is not in the expected state.',409);
                if(trim($v['reason']??'')==='') self::fail('Record a review reason.',422);
                if($e->binding_id) {
                    DB::table('mapa_mobile_bindings')->where(['id'=>$e->binding_id,'company_uuid'=>$company])->update(['enabled'=>false,'revision'=>DB::raw('revision+1'),'updated_at'=>now()]);
                    DB::table('mapa_mobile_sessions')->where('binding_id',$e->binding_id)->whereNull('revoked_at')->update(['revoked_at'=>$this->epoch()]);
                }
                DB::table('mapa_mobile_enrollments')->where('id',$id)->update(['status'=>$operation==='reject'?'rejected':'revoked','revision'=>$e->revision+1,'revoked_at'=>$this->epoch(),'review_reason'=>trim($v['reason']),'updated_at'=>now()]);
            } else self::fail('Unsupported enrollment operation.',404);
            $fresh=DB::table('mapa_mobile_enrollments')->where('id',$id)->first(); $result=['contract'=>Policy::CONTRACT,'ok'=>true,'enrollment'=>$this->adminView($fresh),'replayed'=>false];
            DB::table('mapa_mobile_enrollment_audit')->insert(['company_uuid'=>$company,'enrollment_id'=>$id,'user_uuid'=>$c['user']->uuid,'operation'=>$operation,'revision'=>$fresh->revision,'action_id'=>$v['action_id'],'request_hash'=>$hash,'result'=>json_encode($result,JSON_THROW_ON_ERROR),'created_at'=>now()]);
            return $result;
        },3);
    }
    private function approve(object $e,array $c,array $v): void {
        if($e->status!=='pending' || $e->revoked_at!==null || $e->connect_expires_at<=$this->epoch()) self::fail('Only a current pending enrollment can be approved.',409);
        if(($v['identity_confirmed']??null)!==true || empty($v['vehicle_id']) || empty($v['place_id'])) self::fail('Verify crew identity and select the actual native ambulance and hospital/base.',422);
        $company=$c['company'];
        $matches=$this->crewMatches($e);
        $driver=null;
        if(!empty($v['driver_id'])) {
            if(!in_array($v['driver_id'],array_column($matches,'id'),true)) self::fail('Selected crew is not a current matching organization record. Refresh and review.',409);
            $driver=$this->native(Driver::class,$company)->where('public_id',$v['driver_id'])->lockForUpdate()->first();
            if(!$driver || $driver->online || data_get($driver->meta,'mapa_demo',false) || data_get($driver->meta,'simulated',false)
                || $this->native(Order::class,$company)->where('driver_assigned_uuid',$driver->uuid)->where('dispatched',true)->whereNotIn('status',['completed','canceled','cancelled','failed'])->exists()) self::fail('Existing crew is unavailable or has an active dispatch.',409);
            $existingUser=User::where(['uuid'=>$driver->user_uuid,'company_uuid'=>$company])->lockForUpdate()->first();
            if(!$existingUser || $existingUser->status!=='active' || $existingUser->type!=='driver'
                || !CompanyUser::where(['company_uuid'=>$company,'user_uuid'=>$existingUser->uuid,'status'=>'active'])->exists()
                || DB::table('mapa_mobile_bindings')->where('user_uuid',$existingUser->uuid)->exists())
                self::fail('Existing crew already has mobile access or needs account review. Preserve their account and contact control.',409);
            $this->requirePasswordOnlyEligible($existingUser,$company,true);
        } elseif($matches && ($v['create_new_crew']??false)!==true) self::fail('Review the matching crew records. Select an existing crew or explicitly confirm a different new person.',409);
        $emailOwner=User::withTrashed()->whereRaw('LOWER(TRIM(email)) = ?',[$e->email])->first();
        if($emailOwner && (!$driver || $emailOwner->uuid!==$existingUser->uuid)) self::fail('This email belongs to an existing account. Link that reviewed crew or contact control; credentials are preserved.',409);
        $vehicle=$this->native(Vehicle::class,$company)->where('public_id',$v['vehicle_id'])->lockForUpdate()->first();
        $place=$this->native(Place::class,$company)->where('public_id',$v['place_id'])->where(function($q){$q->whereNull('_key')->orWhere('_key','<>','mapa-voice-v1');})->lockForUpdate()->first();
        if(!$vehicle || !$place || data_get($vehicle->meta,'mapa_demo',false) || data_get($vehicle->meta,'simulated',false) || data_get($place->meta,'mapa_demo',false) || data_get($place->meta,'simulated',false)) self::fail('Select real organization records.',422);
        if(self::normalizedPlate((string)$vehicle->plate_number)==='' || self::normalizedPlate((string)$vehicle->plate_number)!==self::normalizedPlate($e->ambulance_registration)) self::fail('Selected ambulance registration does not match the crew form. Review the details.',409);
        $assigned=$this->native(Driver::class,$company)->where('vehicle_uuid',$vehicle->uuid);
        if($driver) $assigned->where('uuid','<>',$driver->uuid);
        if($vehicle->online || $assigned->exists() || ($driver && $driver->vehicle_uuid && $driver->vehicle_uuid!==$vehicle->uuid)) self::fail('Ambulance or crew is already linked or on duty. Resolve its existing assignment in control.',409);
        $role=Role::where('name','Driver')->where('guard_name','sanctum')->where(function($q)use($company){$q->whereNull('company_uuid')->orWhere('company_uuid',$company);})->first();
        if(!$role || $role->policies()->where('name','AdministratorAccess')->exists()) self::fail('Native crew role requires administrator setup.',503);
        $suffix=bin2hex(random_bytes(12));
        if(!$driver) {
        $user=new User;
        $user->forceFill(['uuid'=>(string)Str::uuid(),'public_id'=>'user_mapa_crew_'.$suffix,'_key'=>'mapa-crew-enrollment-v1','company_uuid'=>$company,'name'=>$e->name,'email'=>$e->email,'phone'=>$e->phone,
            'username'=>'mapa-crew-'.$suffix,'slug'=>'mapa-crew-'.$suffix,'type'=>'driver','status'=>'active','country'=>'IN','timezone'=>'Asia/Kolkata',
            'meta'=>['mapa_demo'=>false,'mapa_enrollment_id'=>$e->id,'identity_reviewed_by'=>$c['user']->public_id,'verification'=>'Operator-approved crew identity; email is a local login identifier, not mailbox verification.','mailbox_created'=>false]]);
        $user->password=bin2hex(random_bytes(32)); $user->email_verified_at=now(); $user->saveQuietly();
        $member=new CompanyUser; $member->forceFill(['uuid'=>(string)Str::uuid(),'_key'=>'mapa-crew-enrollment-v1','company_uuid'=>$company,'user_uuid'=>$user->uuid,'status'=>'active','external'=>false]);
        $member->saveQuietly(); CompanyUser::withoutEvents(fn()=>$member->assignSingleRole($role));
        $driver=new Driver; $driver->forceFill(['uuid'=>(string)Str::uuid(),'public_id'=>'driver_mapa_crew_'.$suffix,'_key'=>'mapa-crew-enrollment-v1','company_uuid'=>$company,'user_uuid'=>$user->uuid,'vehicle_uuid'=>$vehicle->uuid,
            'location'=>new Point((float)$e->latitude,(float)$e->longitude),'online'=>false,'meta'=>['mapa_demo'=>false,'mapa_enrollment_id'=>$e->id,'hospital_place_uuid'=>$place->uuid,
                'registration_location_source'=>'device_reported_registration','registration_location_recorded_at'=>(int)$e->recorded_at,'registration_accuracy_m'=>(float)$e->accuracy_m]]);
        // Native ownership is Driver.vehicle_uuid; Vehicle.driver_uuid is a read-only accessor.
        $driver->saveQuietly();
        } else {
            $user=$existingUser;
            $driver->vehicle_uuid=$vehicle->uuid;
            $driver->meta=array_merge((array)$driver->meta,['hospital_place_uuid'=>$place->uuid,'mapa_enrollment_id'=>$e->id]);
            $driver->saveQuietly();
        }
        $bindingId=(string)Str::uuid(); DB::table('mapa_mobile_bindings')->insert(['id'=>$bindingId,'company_uuid'=>$company,'user_uuid'=>$user->uuid,'role'=>'crew','driver_uuid'=>$driver->uuid,'place_uuid'=>$place->uuid,'enabled'=>true,'revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $binding=DB::table('mapa_mobile_bindings')->where('id',$bindingId)->first(); $context=$this->resolve($binding,$user,true);
        DB::table('mapa_mobile_enrollments')->where('id',$e->id)->update(['status'=>'approved','revision'=>$e->revision+1,'user_uuid'=>$user->uuid,'binding_id'=>$bindingId,'driver_uuid'=>$driver->uuid,'vehicle_uuid'=>$vehicle->uuid,'place_uuid'=>$place->uuid,
            'credential_hash'=>hash('sha256',$user->password),'scope_hash'=>$this->scopeHash($context),'binding_revision'=>1,'approved_by'=>$c['user']->uuid,'approved_at'=>now(),'connect_expires_at'=>$this->epoch()+90*86400000,'updated_at'=>now()]);
        DB::table('mapa_mobile_private_media')->where(['id'=>$e->selfie_media_id,'company_uuid'=>$company,'enrollment_id'=>$e->id])->update(['user_uuid'=>$user->uuid,'driver_uuid'=>$driver->uuid,'vehicle_uuid'=>$vehicle->uuid]);
    }
    public function media(Request $r,string $id) {
        $company=$this->company();
        // Authenticate before looking up a media ID, including unknown IDs.
        $candidate=EnrollmentPolicy::token($r->bearerToken()) ? DB::table('mapa_mobile_enrollments')->where(['company_uuid'=>$company,'connect_hash'=>hash('sha256',$r->bearerToken())])->first() : null;
        $candidate=$candidate && $candidate->revoked_at===null && $candidate->connect_expires_at>$this->epoch() ? $candidate : null;
        $c=$candidate ? null : $this->context($r);
        $media=DB::table('mapa_mobile_private_media')->where(['id'=>$id,'company_uuid'=>$company])->first(); if(!$media) self::fail('Photo not found.',404);
        // A registration token can read only its own selfie, never operational evidence.
        if($candidate) {
            if($media->kind==='selfie' && $media->enrollment_id===$candidate->id) return PrivateMedia::response($media);
            self::fail('Photo not found.',404);
        }
        if(!in_array($c['binding']->role,['admin','superadmin'],true) && !($c['binding']->role==='crew' && $media->user_uuid===$c['user']->uuid && $media->driver_uuid===$c['driver']->uuid && $media->vehicle_uuid===$c['vehicle']?->uuid)) self::fail('Photo not found.',404);
        return PrivateMedia::response($media);
    }
    public function reports(Request $r): array {
        $c=$this->context($r);
        if(!in_array($c['binding']->role,['crew','admin','superadmin'],true)) self::fail('Trip evidence is unavailable for this role.');
        if(!Schema::hasTable('mapa_mobile_trip_reports')) return ['contract'=>Policy::CONTRACT,'reports'=>[],'truncated'=>false];
        $q=DB::table('mapa_mobile_trip_reports')->where('company_uuid',$c['company']);
        if($c['binding']->role==='crew') $q->where(['user_uuid'=>$c['user']->uuid,'driver_uuid'=>$c['driver']->uuid,'vehicle_uuid'=>$c['vehicle']?->uuid]);
        $rows=$q->orderByDesc('received_at')->limit(201)->get(); $reports=[];
        foreach($rows->take(200) as $e) {
            $driver=$this->native(Driver::class,$c['company'])->where('uuid',$e->driver_uuid)->first(); $user=User::where(['uuid'=>$e->user_uuid,'company_uuid'=>$c['company']])->first();
            $vehicle=$this->native(Vehicle::class,$c['company'])->where('uuid',$e->vehicle_uuid)->first();
            $order=DB::table('orders')->where(['uuid'=>$e->order_uuid,'company_uuid'=>$c['company']])->whereNull('deleted_at')->first();
            $reports[]=['id'=>$e->id,'order_id'=>$order?->public_id,'driver_id'=>$driver?->public_id,'driver_name'=>$user?->name,'vehicle_id'=>$vehicle?->public_id,'vehicle_registration'=>$vehicle?->plate_number,
                'note'=>$e->note,'photo_media_id'=>$e->photo_media_id,'latitude'=>(float)$e->latitude,'longitude'=>(float)$e->longitude,'accuracy_m'=>(float)$e->accuracy_m,
                'recorded_at'=>(int)$e->recorded_at,'photo_captured_at'=>(int)$e->photo_captured_at,'received_at'=>(int)$e->received_at];
        }
        return ['contract'=>Policy::CONTRACT,'reports'=>$reports,'truncated'=>$rows->count()>200];
    }
}
