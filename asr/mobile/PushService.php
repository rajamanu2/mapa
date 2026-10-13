<?php
namespace App\MapaMobile;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PushService
{
    protected function storageReady(): bool
    {
        return Schema::hasTable('mapa_mobile_push_devices') && Schema::hasTable('mapa_mobile_push_deliveries');
    }
    public function available(): bool
    {
        return (new FcmTransport)->configured() && $this->storageReady();
    }

    public function hospitalAvailable(): bool
    {
        return $this->available() && config('mapa_mobile.push.hospital_enabled',false)
            && Schema::hasTable('mapa_mobile_hospital_push_devices') && Schema::hasTable('mapa_mobile_hospital_push_deliveries');
    }
    public function eligible(array $c): bool { return ($c['binding']->role==='crew' && (bool)$c['vehicle']) || ($c['binding']->role==='hospital' && (bool)$c['place']); }
    public function availableFor(array $c): bool { return $this->eligible($c) && ($c['binding']->role==='hospital'?$this->hospitalAvailable():$this->available()); }
    private function devices(array $c): string { return $c['binding']->role==='hospital'?'mapa_mobile_hospital_push_devices':'mapa_mobile_push_devices'; }
    public function status(array $c): array
    {
        $available = $this->availableFor($c);
        $registered = $available && DB::table($this->devices($c))->where('session_hash',$c['token_hash'])
            ->whereNull('revoked_at')->where('expires_at','>',(int)round(microtime(true)*1000))->exists();
        return ['available'=>$available,'registered'=>$registered,'provider'=>$available?'fcm':null];
    }

    public function register(Request $request): array
    {
        return DB::transaction(function () use ($request) {
            $c = (new MobileService)->context($request, true);
            if (!$this->eligible($c)) MobileService::fail('A linked crew or hospital account is required for alerts.');
            if (!$this->availableFor($c)) MobileService::fail('Push alerts are not configured. Contact control.',503);
            $input = $request->validate(['installation_id'=>'required|uuid','fcm_token'=>'required|string|min:20|max:4096|regex:/^[A-Za-z0-9:_.-]+$/D']);
            $session = DB::table('mapa_mobile_sessions')->where('token_hash',$c['token_hash'])->first();
            // A phone's refreshed token replaces the old token. Client IDs never select a company or driver.
            $tokenHash = hash('sha256',$input['fcm_token']);
            foreach (['mapa_mobile_push_devices','mapa_mobile_hospital_push_devices'] as $table) if (Schema::hasTable($table)) {
                DB::table($table)->where('token_hash',$tokenHash)->delete();
                DB::table($table)->where('company_uuid',$c['company'])->where('installation_id',$input['installation_id'])->delete();
            }
            DB::table($this->devices($c))->where('company_uuid',$c['company'])->where('installation_id',$input['installation_id'])->delete();
            DB::table($this->devices($c))->insert([
                'token_hash'=>$tokenHash,'installation_id'=>$input['installation_id'],'token_encrypted'=>Crypt::encryptString($input['fcm_token']),
                'company_uuid'=>$c['company'],'binding_id'=>$c['binding']->id,'session_hash'=>$c['token_hash'],
                ...($c['binding']->role==='hospital'?['place_uuid'=>$c['place']->uuid]:['driver_uuid'=>$c['driver']->uuid,'vehicle_uuid'=>$c['vehicle']->uuid]),'expires_at'=>$session->expires_at,
                'created_at'=>now(),'updated_at'=>now()]);
            return ['ok'=>true,'registered'=>true,'expires_at'=>(int)$session->expires_at];
        });
    }

    public function unregister(Request $request): array
    {
        $c = (new MobileService)->context($request);
        if (Schema::hasTable($this->devices($c))) DB::table($this->devices($c))
            ->where('session_hash',$c['token_hash'])->where('company_uuid',$c['company'])->update(['revoked_at'=>(int)round(microtime(true)*1000)]);
        return ['ok'=>true];
    }
}
