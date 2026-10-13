<?php
namespace App\MapaMobile;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Explicit dispatcher confirmation of a caller GPS fix for an existing pickup leg. */
final class ConfirmCallerPickupService extends MobileService
{
 public function confirm(Request $r,string $callId):array {
  if(!config('mapa_mobile.caller_tracking_enabled',false)||!Schema::hasTable('mapa_caller_pickup_confirmations'))self::fail('Caller pickup confirmation is not enabled.',503);
  if($r->input('confirmed')!==true)self::fail('Explicit dispatcher confirmation is required.',422);
  $b=$r->validate(['link_id'=>'required|string|uuid','recorded_at'=>'required|integer|min:1','expected_revision'=>'required|string|size:64','action_id'=>'required|string|max:100','confirmed'=>'required|boolean']);
  if($r->input('confirmed')!==true||!Policy::validAction($b['action_id']))self::fail('Explicit confirmation and a unique action ID are required.',422);
  return DB::transaction(function()use($r,$callId,$b){
   $c=$this->context($r,true);
   if(!in_array($c['binding']->role,['operator','admin','superadmin'],true))self::fail('Dispatcher access required.');
   $call=DB::table('mapa_voice_calls')->where(['id'=>$callId,'company_uuid'=>$c['company']])->lockForUpdate()->first();
   if(!$call)self::fail('Call not found.',404);
   if($call->demo)self::fail('Caller pickup confirmation is unavailable for training calls.',409);
   $order=$call->order_uuid?$this->native(Order::class,$c['company'])->where('uuid',$call->order_uuid)->lockForUpdate()->first():null;
   if(!$order)self::fail('Dispatch the call before confirming its live pickup.',409);
   $bodyHash=Policy::fingerprint('confirm-caller-pickup',['call_id'=>$callId,'user_uuid'=>$c['user']->uuid,...$b]);
   $existing=DB::table('mapa_caller_pickup_confirmations')->where(['company_uuid'=>$c['company'],'action_id'=>$b['action_id']])->lockForUpdate()->first();
   if($existing){
    if(!hash_equals($existing->body_hash,$bodyHash)||$existing->order_uuid!==$order->uuid)self::fail('This action ID was already used for a different confirmation.',409);
    return [...json_decode($existing->result,true,512,JSON_THROW_ON_ERROR),'replayed'=>true];
   }
   if(ConnectedTrackingPolicy::closed($call->state)||ConnectedTrackingPolicy::closed($order->status)||!$order->dispatched||!in_array($order->status,['dispatched','started'],true))self::fail('Pickup confirmation is only available before patient pickup.',409);
   if(Schema::hasTable('mapa_mobile_hospital_handovers')&&DB::table('mapa_mobile_hospital_handovers')->where(['company_uuid'=>$c['company'],'order_uuid'=>$order->uuid])->exists())self::fail('Patient pickup is complete. Caller location cannot change this trip.',409);
   $link=DB::table('mapa_tracking_links')->where(['id'=>$b['link_id'],'call_id'=>$callId,'company_uuid'=>$c['company']])->lockForUpdate()->first();
   $now=(int)round(microtime(true)*1000);
   if(!ConnectedTrackingPolicy::active($link,$now))self::fail('Caller link expired or was revoked. Refresh the call.',409);
   $review=json_decode($call->review?:($call->draft?:'{}'),true);
   if(!is_array($review))self::fail('Call details must be reviewed first.',409);
   if(($link->scope??'pickup')==='full_journey'){
    try{$phone=CallerPhone::normalize($review['callback_number']??'');}catch(\InvalidArgumentException $e){$phone='';}
    if($phone===''||!is_string($link->callback_hash)||!hash_equals($link->callback_hash,hash('sha256',$phone)))self::fail('Callback changed. Request a new trip link.',409);
    if($link->order_uuid!==null&&$link->order_uuid!==$order->uuid)self::fail('Trip changed. Request a new trip link.',409);
   }
   $position=DB::table('mapa_caller_locations')->where(['link_id'=>$link->id,'company_uuid'=>$c['company']])->lockForUpdate()->first();
   $callerEvidence=CallerPickupEvidence::read($position,$now);
   $confirmedCandidate=$callerEvidence&&(int)$callerEvidence['recorded_at']===(int)$b['recorded_at'];
   if(!$confirmedCandidate&&(!$position||(int)$position->recorded_at!==(int)$b['recorded_at']))self::fail('Caller location changed. Refresh and confirm the latest position.',409);
   // A frozen caller-confirmed candidate stays separate from later passive phone fixes.
   // The operator must still review the current order revision and explicitly accept it.
   $fix=CallerPickupEvidence::fix($confirmedCandidate?$callerEvidence:(array)$position);
   if(!ConnectedTrackingPolicy::fix($fix,$now))self::fail('Caller location is stale or inaccurate. Request a new GPS fix.',422);
   $payload=$order->payload_uuid?$this->native(Payload::class,$c['company'])->where('uuid',$order->payload_uuid)->lockForUpdate()->first():null;
   if(!$payload||!$payload->pickup_uuid)self::fail('A valid native pickup must be set first.',409);
   // A payload can be shared by native imports. Never move another trip's pickup.
   if(DB::table('orders')->where('payload_uuid',$payload->uuid)->where('uuid','<>',$order->uuid)->whereNull('deleted_at')->exists())self::fail('This pickup payload is shared. Control must separate the trips first.',409);
   $place=$this->native(Place::class,$c['company'])->where('uuid',$payload->pickup_uuid)->lockForUpdate()->first();
   if(!$place)self::fail('The native pickup place is unavailable.',409);
   $order->setRelation('payload',$payload);
   if(!hash_equals($this->revision($order),$b['expected_revision']))self::fail('Trip changed. Refresh and confirm the pickup again.',409);
   $id=(string)Str::uuid();$newPlaceId=(string)Str::uuid();
   $copy=$place->getRawOriginal();foreach(['id','uuid','public_id','created_at','updated_at','deleted_at','location'] as $field)unset($copy[$field]);
   $copy['uuid']=$newPlaceId;$copy['public_id']='place_mapa_pickup_'.str_replace('-','',$newPlaceId);$copy['created_at']=now();$copy['updated_at']=now();
   $copy['location']=DB::raw("ST_GeomFromText('POINT(".$fix['longitude'].' '.$fix['latitude'].")',0)");
   foreach(['latitude','longitude'] as $field)if(Schema::hasColumn('places',$field))$copy[$field]=$fix[$field];
   DB::table('places')->insert($copy);
   DB::table('payloads')->where(['uuid'=>$payload->uuid,'company_uuid'=>$c['company']])->update(['pickup_uuid'=>$newPlaceId,'updated_at'=>now()]);
   $meta=$order->meta;if(is_string($meta))$meta=json_decode($meta,true);if(is_object($meta))$meta=(array)$meta;if(!is_array($meta))$meta=[];
   $meta['mapa_caller_pickup_confirmation']=['id'=>$id,'recorded_at'=>$fix['recorded_at'],'confirmed_by'=>$c['user']->uuid,
    'caller_confirmed_at'=>$confirmedCandidate?$callerEvidence['confirmed_at']:null];
   // Changing native order facts invalidates crew acknowledgement/revision. No
   // lifecycle events are emitted here: they can send provider traffic mid-transaction.
   DB::table('orders')->where(['uuid'=>$order->uuid,'company_uuid'=>$c['company']])->update(['meta'=>json_encode($meta,JSON_THROW_ON_ERROR),'updated_at'=>now()]);
   $review['latitude']=$fix['latitude'];$review['longitude']=$fix['longitude'];$review['coordinates_confirmed']=true;
   $callUpdate=['review'=>json_encode($review,JSON_THROW_ON_ERROR),'updated_at'=>now()];
   if(Schema::hasColumn('mapa_voice_calls','revision'))$callUpdate['revision']=(int)$call->revision+1;
   DB::table('mapa_voice_calls')->where(['id'=>$callId,'company_uuid'=>$c['company']])->update($callUpdate);
   if(($link->scope??'pickup')==='full_journey'&&$link->order_uuid===null)DB::table('mapa_tracking_links')->where('id',$link->id)->update(['order_uuid'=>$order->uuid,'updated_at'=>now()]);
   $order->refresh();$order->unsetRelation('payload');
   $result=['ok'=>true,'replayed'=>false,'confirmation_id'=>$id,'order_id'=>$order->public_id,'pickup'=>['label'=>(string)$place->name,...$fix],
    'order_revision'=>$this->revision($order),'crew_must_review'=>true];
   DB::table('mapa_caller_pickup_confirmations')->insert(['id'=>$id,'company_uuid'=>$c['company'],'call_id'=>$callId,'order_uuid'=>$order->uuid,'link_id'=>$link->id,'confirmed_by'=>$c['user']->uuid,
    'action_id'=>$b['action_id'],'body_hash'=>$bodyHash,'request_hash'=>Policy::fingerprint('confirm-caller-pickup',['body_hash'=>$bodyHash,...$fix]),'expected_revision'=>$b['expected_revision'],
    'previous_pickup_uuid'=>$place->uuid,'pickup_uuid'=>$newPlaceId,...$fix,'result'=>json_encode($result,JSON_THROW_ON_ERROR),'created_at'=>now()]);
   $company=$c['company'];$orderId=$order->uuid;
   DB::afterCommit(function()use($company,$orderId,$order){
    foreach(['orders','routes','coordinates','operations-monitor'] as $endpoint){try{\Illuminate\Support\Facades\Cache::increment("live:{$company}:{$endpoint}:version");\Illuminate\Support\Facades\Cache::tags(["live:{$company}:{$endpoint}"])->flush();}catch(\Throwable $e){/* Persistence succeeded; never repeat this write because cache is unavailable. */}}
    try{\Illuminate\Support\Facades\Cache::forget("order:{$orderId}:tracker");$order->flushAttributesCache();}catch(\Throwable $e){}
   });
   return $result;
  });
 }
}
