<?php
namespace App\MapaMobile;

use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/** Native console requests retain their own credential; no secondary session is issued. */
class ConsoleEnrollmentService extends EnrollmentService
{
    public function context(Request $request, bool $lock=false): array
    {
        $company=$this->company();
        if($request->header('Access-Console-Sandbox') || $request->header('Access-Console-Sandbox-Key'))
            self::fail('Crew registration review is unavailable in a sandbox console.',403);
        $raw=(string)$request->bearerToken();
        if(!preg_match('/^[A-Za-z0-9|._~-]{20,1024}$/D',$raw)) self::fail('Sign in to the MAPA console.',401);
        $model=Sanctum::$personalAccessTokenModel;
        $token=$model::findToken($raw);
        if(!$token instanceof PersonalAccessToken) self::fail('The native console credential is invalid or revoked.',401);
        if($lock) {
            $originalHash=(string)$token->token;
            $token=$model::whereKey($token->getKey())->lockForUpdate()->first();
            if(!$token || !hash_equals($originalHash,(string)$token->token)) self::fail('The native console credential changed.',401);
        }
        $ttl=config('sanctum.expiration');
        if(($ttl && (!$token->created_at || !$token->created_at->gt(now()->subMinutes((int)$ttl))))
            || ($token->expires_at && $token->expires_at->isPast()) || !$token->can('*'))
            self::fail('The native console credential expired or lacks console access.',401);
        $owner=$token->tokenable;
        if(!$owner instanceof User || (string)$token->name!==(string)$owner->uuid)
            self::fail('A completed native console sign-in is required.',401);
        $q=User::where('uuid',$owner->uuid);
        $user=($lock?$q->lockForUpdate():$q)->first();
        if(!$user || $user->status!=='active' || $user->company_uuid!==$company)
            self::fail('The active ASR Health console membership is required.',403);
        $q=DB::table('mapa_mobile_bindings')->where(['company_uuid'=>$company,'user_uuid'=>$user->uuid]);
        $binding=($lock?$q->lockForUpdate():$q)->first();
        if(!$binding || !in_array($binding->role,['admin','superadmin'],true))
            self::fail('This native account is not an approved crew registration reviewer.',403);
        // Reuse current enabled binding, active company membership, verification,
        // explicit super-admin allowlist, and fail-closed MFA/reset policy checks.
        $context=$this->resolve($binding,$user,$lock);
        return [...$context,'native_token_id'=>$token->getKey()];
    }
    public function identity(Request $request): array
    {
        $context=$this->staff($request);
        return [...$this->capabilities($context),'native_user_uuid'=>$context['user']->uuid];
    }
    /** Read-only intake projection for Operations; a saved intake is not a dispatched order. */
    public function intake(Request $request): array
    {
        $context=$this->context($request);
        if(!config('mapa_voice.enabled') || !Schema::hasTable('mapa_voice_calls'))
            self::fail('Call intake is unavailable.',503);
        $query=DB::table('mapa_voice_calls as calls')->where('calls.company_uuid',$context['company'])->where('calls.provider','<>','asrhealth')
            ->leftJoin('orders as orders',function($join){
                $join->on('orders.uuid','=','calls.order_uuid')->on('orders.company_uuid','=','calls.company_uuid')->whereNull('orders.deleted_at');
            });
        if(!config('mapa_voice.demo_enabled'))$query->where('calls.demo',false);
        $rows=$query->select('calls.id','calls.revision','calls.state','calls.demo','calls.draft','calls.review','calls.created_at','calls.updated_at','orders.public_id as order_id')
            ->orderByDesc('calls.updated_at')->orderByDesc('calls.id')->limit(101)->get();
        $clean=static fn($value,$limit)=>is_string($value)?mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/u',' ',$value)??''),0,$limit):'';
        $calls=$rows->take(100)->map(static function($row)use($clean){
            $details=json_decode($row->review?:$row->draft,true);if(!is_array($details))$details=[];
            return ['id'=>$row->id,'revision'=>(int)$row->revision,'state'=>$row->state,'demo'=>(bool)$row->demo,
                'caller_label'=>$clean($details['caller_name']??'',120),'pickup_address'=>$clean($details['pickup_address']??'',1000),
                'created_at'=>\Carbon\Carbon::parse($row->created_at,'UTC')->toIso8601String(),
                'updated_at'=>\Carbon\Carbon::parse($row->updated_at,'UTC')->toIso8601String(),'order_id'=>$row->order_id];
        })->all();
        return ['contract'=>Policy::CONTRACT,'company_id'=>$context['company'],'calls'=>$calls,'truncated'=>$rows->count()>100];
    }
}
