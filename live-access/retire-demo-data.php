<?php
/** Exact reviewed DEMO retirement. Default dry-run; query-builder only, no dispatch/events/files/hard deletes. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require '/fleetbase/api/vendor/autoload.php';
$app=require '/fleetbase/api/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
$company='29eff7bc-7b9b-4d8a-b1b4-55f33681bd2d';
$apply=in_array('--apply',$argv,true);$dry=in_array('--dry-run',$argv,true);
if($apply===$dry)throw new RuntimeException('Use exactly --dry-run or --apply with the reviewed private plan on stdin.');
$raw=stream_get_contents(STDIN,2097153);if(strlen($raw)>2097152)throw new RuntimeException('Plan too large.');
$p=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$scope=$p['scope']??[];
$allowed=['company_users','drivers','mapa_mobile_bindings','mapa_voice_calls','orders','payloads','places','positions','tracking_numbers','users','vehicles'];
if(($p['version']??null)!==1||($p['company_uuid']??'')!==$company||array_keys($scope)!==$allowed||config('mapa.company_uuid')!==$company)throw new RuntimeException('Plan/company/table scope mismatch.');
foreach($scope as $t=>$ids){if(!is_array($ids)||count($ids)>5000||count($ids)!==count(array_unique($ids)))throw new RuntimeException('Invalid exact scope.');foreach($ids as $id)if(!is_string($id)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$id))throw new RuntimeException('Only UUID record keys are allowed.');$sorted=$ids;sort($sorted);if($sorted!==$ids)throw new RuntimeException('Plan must be canonical.');}
$fp=hash('sha256',json_encode($scope,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
if(!hash_equals($fp,(string)($p['plan_fingerprint']??'')))throw new RuntimeException('Plan fingerprint mismatch.');
$confirmation='--confirm='.$fp;if($apply&&!in_array($confirmation,$argv,true))throw new RuntimeException('Explicit exact fingerprint confirmation is required.');
$datasets=['mapa-demo-v1','mapa-mobile-pilot-v1','mapa-voice-demo-v1'];
$own=static function($row,string $table)use($datasets):bool{$m=json_decode($row->meta??'{}',true);$m=is_array($m)?$m:[];if(array_key_exists('mapa_demo',$m))return $m['mapa_demo']===true;return !property_exists($row,'meta')&&in_array($row->_key??null,$datasets,true)&&in_array($table,['positions','tracking_numbers','company_users'],true);};
$idfield=static fn($t)=>in_array($t,['mapa_mobile_bindings','mapa_voice_calls'],true)?'id':'uuid';
$contains=static fn($t,$id)=>is_string($id)&&in_array($id,$scope[$t]??[],true);
if($dry)DB::statement('SET TRANSACTION READ ONLY');DB::beginTransaction();
try{
 $cq=DB::table('companies')->where('uuid',$company)->where('name','ASR Health')->whereNull('deleted_at');if($apply)$cq->lockForUpdate();if(!$cq->first())throw new RuntimeException('ASR company identity changed.');
 if(config('mapa_voice.demo_enabled'))throw new RuntimeException('Disable training intake before retirement.');
 $rows=[];
 foreach($scope as $t=>$ids){$q=DB::table($t)->where('company_uuid',$company)->whereIn($idfield($t),$ids);if($apply)$q->lockForUpdate();$got=$q->get();if(count($got)!==count($ids))throw new RuntimeException('Selected scope missing/foreign: '.$t);foreach($got as $r){$id=$r->{$idfield($t)};$expect=$p['expected'][$t][$id]??null;if(!$expect||($expect['active']??false)!==true)throw new RuntimeException('Missing exact expected record: '.$t);if(property_exists($r,'deleted_at')&&$r->deleted_at!==null)throw new RuntimeException('Already retired; obtain a new audit.');if(property_exists($r,'revision')&&($expect['revision']??null)!=(int)$r->revision)throw new RuntimeException('Revision changed: '.$t);foreach($expect['references']??[] as $k=>$v)if(!property_exists($r,$k)||(string)$r->$k!==(string)$v)throw new RuntimeException('Reference changed: '.$t);$rows[$t][$id]=$r;}}
 foreach($rows as $t=>$rs)foreach($rs as $r){
  if($t==='orders'&&(($r->driver_assigned_uuid&&!$contains('drivers',$r->driver_assigned_uuid))||($r->vehicle_assigned_uuid&&!$contains('vehicles',$r->vehicle_assigned_uuid))))throw new RuntimeException('DEMO order is assigned to preserved driver/vehicle.');
  if($t==='drivers'&&(!$contains('users',$r->user_uuid)||($r->vehicle_uuid&&!$contains('vehicles',$r->vehicle_uuid))))throw new RuntimeException('DEMO driver links preserved user/vehicle.');
  if($t==='company_users'&&!$contains('users',$r->user_uuid))throw new RuntimeException('DEMO membership belongs to preserved user.');
  if($t==='payloads'&&(($r->pickup_uuid&&!$contains('places',$r->pickup_uuid))||($r->dropoff_uuid&&!$contains('places',$r->dropoff_uuid))))throw new RuntimeException('DEMO payload links preserved place.');
  if($t==='tracking_numbers'&&(!$contains('orders',$r->owner_uuid)||!str_ends_with((string)$r->owner_type,'\\Order')))throw new RuntimeException('DEMO tracking belongs to preserved owner.');
  if($t==='positions'&&(!in_array($r->subject_type,['Fleetbase\\FleetOps\\Models\\Driver','Fleetbase\\FleetOps\\Models\\Vehicle','Fleetbase\\Models\\Driver','Fleetbase\\Models\\Vehicle'],true)||!$contains(str_ends_with((string)$r->subject_type,'\\Driver')?'drivers':'vehicles',$r->subject_uuid)))throw new RuntimeException('DEMO position belongs to preserved subject.');
  if($t==='mapa_voice_calls'){if(!(bool)$r->demo)throw new RuntimeException('Live voice call cannot be retired.');if($r->order_uuid&&!$contains('orders',$r->order_uuid))throw new RuntimeException('DEMO call points to preserved order.');continue;}
  if($t==='mapa_mobile_bindings'){if(!$contains('users',$r->user_uuid)||($r->driver_uuid&&!$contains('drivers',$r->driver_uuid))||($r->place_uuid&&!$contains('places',$r->place_uuid)))throw new RuntimeException('Binding links preserved operational records.');continue;}
  if($own($r,$t))continue;
  if($t==='positions'&&$contains(str_ends_with((string)$r->subject_type,'\\Driver')?'drivers':'vehicles',$r->subject_uuid)&&in_array($r->subject_type,['Fleetbase\\FleetOps\\Models\\Driver','Fleetbase\\FleetOps\\Models\\Vehicle','Fleetbase\\Models\\Driver','Fleetbase\\Models\\Vehicle'],true))continue;
  if(in_array($t,['payloads','tracking_numbers'],true)){
   $col=$t==='payloads'?'payload_uuid':'tracking_number_uuid';$owners=DB::table('orders')->where($col,$r->uuid)->whereNull('deleted_at')->get(['uuid','company_uuid']);
   if(count($owners)&&$owners->every(fn($o)=>$o->company_uuid===$company&&$contains('orders',$o->uuid))){if($t==='payloads'&&(!$contains('places',$r->pickup_uuid)||!$contains('places',$r->dropoff_uuid)))throw new RuntimeException('Unmarked payload links preserved place.');if($t==='tracking_numbers'&&!$contains('orders',$r->owner_uuid))throw new RuntimeException('Tracking owner changed.');continue;}
  }
  throw new RuntimeException('Missing exact DEMO provenance: '.$t);
 }
 // Fail closed if any preserved active operational row references a resource that would disappear.
 $checks=[['drivers','user_uuid','users'],['drivers','vehicle_uuid','vehicles'],['orders','driver_assigned_uuid','drivers'],['orders','vehicle_assigned_uuid','vehicles'],['orders','payload_uuid','payloads'],['orders','tracking_number_uuid','tracking_numbers'],['payloads','pickup_uuid','places'],['payloads','dropoff_uuid','places'],['waypoints','place_uuid','places'],['vendors','place_uuid','places'],['company_users','user_uuid','users']];
 foreach($checks as [$table,$field,$target]){if(!Schema::hasTable($table)||!Schema::hasColumn($table,$field))continue;$q=DB::table($table)->whereIn($field,$scope[$target]);if(Schema::hasColumn($table,'deleted_at'))$q->whereNull('deleted_at');if(isset($scope[$table]))$q->whereNotIn('uuid',$scope[$table]);if($q->exists())throw new RuntimeException('Preserved/foreign active reference blocks retirement: '.$table.'.'.$field);}
 foreach(['mapa_mobile_bindings','mapa_mobile_enrollments'] as $t){$q=DB::table($t)->where(function($q)use($scope){$q->whereIn('user_uuid',$scope['users'])->orWhereIn('driver_uuid',$scope['drivers'])->orWhereIn('place_uuid',$scope['places']);});if($t==='mapa_mobile_bindings')$q->whereNotIn('id',$scope[$t]);else $q->orWhereIn('vehicle_uuid',$scope['vehicles']);if($q->exists())throw new RuntimeException('Preserved enrollment/binding references selected DEMO scope.');}
 if(DB::table('mapa_voice_calls')->whereIn('order_uuid',$scope['orders'])->where('demo',false)->exists())throw new RuntimeException('Live call references a selected DEMO order.');
 $bindingIds=$scope['mapa_mobile_bindings'];
 // Revocation scope is derived exclusively from reviewed disabled DEMO bindings, never caller-supplied tokens.
 if(DB::table('mapa_mobile_sessions')->where('company_uuid',$company)->whereIn('binding_id',$bindingIds)->whereNotIn('user_uuid',$scope['users'])->exists())throw new RuntimeException('Session identity does not match DEMO binding.');
 if(DB::table('mapa_mobile_push_devices')->where('company_uuid',$company)->whereIn('binding_id',$bindingIds)->where(function($q)use($scope){$q->whereNotIn('driver_uuid',$scope['drivers'])->orWhereNotIn('vehicle_uuid',$scope['vehicles']);})->exists())throw new RuntimeException('Push device links preserved driver/vehicle.');
 $sessions=DB::table('mapa_mobile_sessions')->where('company_uuid',$company)->whereIn('binding_id',$bindingIds)->whereNull('revoked_at');$sessionCount=$sessions->count();
 if(DB::table('mapa_mobile_push_devices')->where('company_uuid',$company)->whereIn('binding_id',$bindingIds)->exists())throw new RuntimeException('Push registrations appeared after the reviewed zero-device audit; review their event ownership separately.');
 $devices=DB::table('mapa_mobile_push_devices')->where('company_uuid',$company)->whereIn('binding_id',$bindingIds)->whereNull('revoked_at');$deviceTokens=$devices->pluck('token_hash')->all();
 $deliveries=DB::table('mapa_mobile_push_deliveries')->whereIn('token_hash',$deviceTokens)->whereIn('status',['pending','sending','retry']);$deliveryCount=$deliveries->count();
 $counts=array_map('count',$scope);$counts['mobile_sessions_to_revoke']=$sessionCount;$counts['push_devices_to_revoke']=count($deviceTokens);$counts['pending_push_to_retire']=$deliveryCount;
 if($apply){$now=now()->toDateTimeString();$ms=(int)round(microtime(true)*1000);$sessions->update(['revoked_at'=>$ms,'updated_at'=>$now]);$devices->update(['revoked_at'=>$ms,'updated_at'=>$now]);// Current reviewed plan has zero push registrations/deliveries; no delivery rows are changed.

  foreach($scope as $t=>$ids){$q=DB::table($t)->where('company_uuid',$company)->whereIn($idfield($t),$ids);$change=['updated_at'=>$now];
   if($t==='mapa_mobile_bindings')$change+=['enabled'=>false,'revision'=>DB::raw('revision+1')];
   elseif($t==='mapa_voice_calls')$change+=['state'=>'dismissed','dismiss_reason'=>'MAPA DEMO data retired by reviewed cleanup; original history retained.','revision'=>DB::raw('revision+1')];
   else{$change+=['deleted_at'=>$now];if(in_array($t,['users','company_users'],true))$change+=['status'=>'inactive'];if(in_array($t,['drivers','vehicles'],true))$change+=['online'=>false,'status'=>'unavailable'];}
   $q->update($change);
  }
  DB::commit();
 }else DB::rollBack();
 echo json_encode(['ok'=>true,'mode'=>$apply?'applied':'dry_run','company_uuid'=>$company,'plan_fingerprint'=>$fp,'counts'=>$counts,'history_retained'=>true,'hard_deleted_rows'=>0,'notifications_sent'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
}catch(Throwable $e){if(DB::transactionLevel())DB::rollBack();throw $e;}
