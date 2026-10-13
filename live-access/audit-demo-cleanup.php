<?php
/** MAPA exact-provenance DEMO audit. CLI SELECT only; no PII, credentials or coordinates in output. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$company='29eff7bc-7b9b-4d8a-b1b4-55f33681bd2d';
if ((string) config('mapa.company_uuid') !== $company || !DB::table('companies')->where('uuid',$company)->where('name','ASR Health')->whereNull('deleted_at')->exists()) throw new RuntimeException('Configured ASR company mismatch.');
$datasets=['mapa-demo-v1','mapa-mobile-pilot-v1','mapa-voice-demo-v1'];
$tables=['users','company_users','drivers','vehicles','places','orders','payloads','waypoints','tracking_numbers','positions','mapa_mobile_bindings','mapa_mobile_sessions','mapa_mobile_driver_states','mapa_mobile_order_states','mapa_mobile_enrollments','mapa_mobile_private_media','mapa_mobile_trip_reports','mapa_mobile_push_devices','mapa_mobile_push_deliveries','mapa_voice_calls'];
DB::statement('SET TRANSACTION READ ONLY'); DB::beginTransaction();
try {
 $schema=[]; $records=[]; $summary=[]; $foreignKeys=[];
 $db=DB::connection()->getDatabaseName();
 foreach($tables as $table){
  $columns=DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA',$db)->where('TABLE_NAME',$table)->orderBy('ORDINAL_POSITION')->pluck('COLUMN_NAME')->all();
  if(!$columns){$summary[$table]=['missing'=>true];continue;}
  $schema[$table]=$columns;
  $safe=array_values(array_filter($columns,static fn($c)=>in_array($c,['uuid','id','public_id','company_uuid','_key','meta','demo','status','state','online','enabled','revision','binding_revision','deleted_at','updated_at','expires_at','revoked_at','subject_type','owner_type','role'],true)||str_ends_with($c,'_uuid')||in_array($c,['binding_id','enrollment_id','call_id','order_id','driver_id','media_id'],true)));
  // Never select plaintext keys, token hashes, credential hashes, names, contact details, coordinates or image bytes.
  $q=DB::table($table)->select($safe);
  if(in_array('company_uuid',$columns,true)) $q->where('company_uuid',$company);
  else { $summary[$table]=['unscoped_skipped'=>true]; continue; }
  $rows=$q->get(); $counts=['total'=>count($rows),'active'=>0,'explicit_demo'=>0,'asr_import'=>0,'preserved_unmarked'=>0];
  foreach($rows as $object){$r=(array)$object;$m=json_decode($r['meta']??'{}',true);$m=is_array($m)?$m:[]; $provenance=[];
   if(($m['mapa_demo']??null)===true)$provenance[]='meta.mapa_demo=true';
   if(in_array($m['demo_dataset']??null,$datasets,true))$provenance[]='meta.demo_dataset:'.$m['demo_dataset'];
   if(in_array($r['_key']??null,$datasets,true))$provenance[]='_key:'.$r['_key'];
   if($table==='mapa_voice_calls' && ($r['demo']??0)==1)$provenance[]='demo=true';
   if(isset($m['asr_import']))$counts['asr_import']++;
   $active= !array_key_exists('deleted_at',$r)||$r['deleted_at']===null; if($active)$counts['active']++;
   if($provenance)$counts['explicit_demo']++;else $counts['preserved_unmarked']++;
   $key=(string)($r['uuid']??$r['id']??$r['order_uuid']??$r['driver_uuid']??'');
   if(!$key){ // Sessions deliberately exclude token_hash; stable audit identity from scoped safe fields.
    $key='audit-'.substr(hash('sha256',json_encode($r)),0,24);
   }
   $refs=[];foreach($r as $k=>$v)if((str_ends_with($k,'_uuid')&&$k!=='company_uuid'&&$k!=='uuid')||in_array($k,['binding_id','enrollment_id','call_id','order_id','driver_id','media_id'],true))if($v!==null&&$v!=='')$refs[$k]=$v;
   $safeRecord=['id'=>$key,'active'=>$active,'provenance'=>$provenance,'references'=>$refs];
   foreach(['public_id','subject_type','owner_type','role','status','state','online','enabled','revision','binding_revision','deleted_at','updated_at','expires_at','revoked_at'] as $k)if(array_key_exists($k,$r))$safeRecord[$k]=$r[$k];
   // Public IDs can be real identifiers; keep only in private audit file. No contents of arbitrary metadata are emitted.
   $records[$table][]=$safeRecord;
  }
  $summary[$table]=$counts;
 }
 $nativeFks=DB::table('information_schema.KEY_COLUMN_USAGE')->where('TABLE_SCHEMA',$db)->whereNotNull('REFERENCED_TABLE_NAME')->get(['TABLE_NAME','COLUMN_NAME','REFERENCED_TABLE_NAME','REFERENCED_COLUMN_NAME']);
 foreach($nativeFks as $fk)if(in_array($fk->REFERENCED_TABLE_NAME,$tables,true))$foreignKeys[]=(array)$fk;
 $demoIds=[];foreach($records as $t=>$items)foreach($items as $r)if($r['provenance'])$demoIds[$r['id']]=[$t,$r['active']];
 $dependent=[];foreach($records as $t=>$items)foreach($items as $r)foreach($r['references'] as $field=>$value)if(isset($demoIds[$value])){$dependent[$t][]=['id'=>$r['id'],'active'=>$r['active'],'explicit_demo'=>(bool)$r['provenance'],'field'=>$field,'target'=>$value];}
 $basis=['company_uuid'=>$company,'schema'=>$schema,'records'=>$records,'dependencies'=>$dependent];
 $out=['version'=>1,'generated_at'=>gmdate('c'),'read_only'=>true,'company_uuid'=>$company,'summary'=>$summary,...$basis,'foreign_keys'=>$foreignKeys,'plan_fingerprint'=>hash('sha256',json_encode($basis,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];
 DB::rollBack(); echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
} catch(Throwable $e){if(DB::transactionLevel())DB::rollBack(); throw $e;}
