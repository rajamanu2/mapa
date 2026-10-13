<?php
/** Real native query/review/dispatch assertions in fresh isolated QA only. */
if(getenv('MAPA_VOICE_QA_ISOLATED')!=='1')throw new RuntimeException('Dedicated isolated voice QA is required.');
$destinationRequest=fn($page='1')=>Illuminate\Http\Request::create('/test','GET',['destination_page'=>$page],[],[],['HTTP_AUTHORIZATION'=>'Bearer '.$tokens['admin']]);
foreach(['0','-1','1.2','100000','one',['1']] as $invalidPage)denied(fn()=>$s->show($destinationRequest($invalidPage),$liveCallback['id']),422);
for($i=0;$i<205;$i++)row('places',['company_uuid'=>$company,'name'=>'DEMO 000 training destination '.str_pad((string)$i,3,'0',STR_PAD_LEFT),'location'=>$point,'latitude'=>18.083,'longitude'=>82.669]);
$liveFirst=$s->show($destinationRequest(),$liveCallback['id']);
check(in_array($receivingId,array_column($liveFirst['places'],'id'),true),'205 earlier DEMO destinations do not crowd out live options');
$added=[];
for($i=0;$i<201;$i++){
    $id=row('places',['company_uuid'=>$company,'name'=>'DEMO Destination same name','meta'=>$liveMeta,'location'=>$point,'latitude'=>18.083,'longitude'=>82.669]);
    $added[]=DB::table('places')->where('uuid',$id)->value('public_id');
}
$pageOne=$s->show($destinationRequest(),$liveCallback['id']);$pageTwo=$s->show($destinationRequest('2'),$liveCallback['id']);
check(count($pageOne['places'])===200&&$pageOne['destinations_has_more']===true&&$pageOne['destination_page_size']===200,'destination options are bounded with explicit more state');
check($pageTwo['destination_page']===2&&$pageTwo['destinations_has_more']===false,'second destination page advertises exhaustion');
$pageIds=array_merge(array_column($pageOne['places'],'id'),array_column($pageTwo['places'],'id'));
check(count($pageIds)===count(array_unique($pageIds))&&!array_diff($added,$pageIds),'stable destination pages retain all >200 same-name destinations without duplicate IDs');
check($pageOne['places']===$s->show($destinationRequest(),$liveCallback['id'])['places'],'unchanged destination ordering is deterministic');
check(!in_array($receivingId,array_column($pageOne['places'],'id'),true)&&$pageOne['selected_destination']['id']===$receivingId,'saved valid destination is separately included even beyond page one');
$allOptions=static function($service,$callId)use($destinationRequest){$rows=[];for($page=1;$page<10;$page++){$data=$service->show($destinationRequest((string)$page),$callId);$rows=array_merge($rows,$data['places']);if(!$data['destinations_has_more'])return $rows;}throw new RuntimeException('Unexpected fixture destination count');};
foreach([true,false,null,'true',1,'missing'] as $flag){
    $meta=['isolated_test'=>true];if($flag!=='missing')$meta['mapa_demo']=$flag;
    $uuid=row('places',['company_uuid'=>$company,'name'=>'DEMO Flag '.json_encode($flag),'meta'=>json_encode($meta),'location'=>$point]);
    $id=DB::table('places')->where('uuid',$uuid)->value('public_id');
    check(in_array($id,array_column($allOptions($s,$liveCallback['id']),'id'),true)===!App\MapaVoice\Policy::demo($meta),'SQL live flag semantics match exact boolean Policy demo semantics');
}
// Generated pickup supplied directly must fail, not merely disappear from the UI.
$generatedId=DB::table('places')->where('public_id','like','place_mapa_voice_%')->where('_key','mapa-voice-v1')->value('public_id');
$boundaryCall=$s->mutate(voiceRequest([...$liveBody,'action_id'=>(string)Str::uuid()],$tokens['admin']),'manual')['call'];
$boundaryReview=[...$liveReview,'action_id'=>(string)Str::uuid(),'expected_revision'=>$boundaryCall['revision'],'dropoff_id'=>$generatedId];
$priorCalls=DB::table('mapa_voice_calls')->where('id',$boundaryCall['id'])->first();
denied(fn()=>$s->mutate(voiceRequest($boundaryReview,$tokens['admin']),'review',$boundaryCall['id']),422);
check(DB::table('mapa_voice_calls')->where('id',$boundaryCall['id'])->value('revision')===$priorCalls->revision,'invalid receiving place leaves review revision unchanged');
DB::table('orders')->where('company_uuid',$company)->update(['status'=>'completed']);
DB::table('mapa_mobile_driver_states')->where('driver_uuid',$driver)->update(['recorded_at'=>(int)round(microtime(true)*1000)]);
$boundaryReady=$s->mutate(voiceRequest([...$boundaryReview,'action_id'=>(string)Str::uuid(),'dropoff_id'=>$receivingId],$tokens['admin']),'review',$boundaryCall['id']);
check(count($boundaryReady['candidates'])===1,'boundary test uses a real eligible crew after valid review');
$destinationDispatch=['action_id'=>(string)Str::uuid(),'expected_revision'=>$boundaryReady['call']['revision'],'confirmed'=>true,'driver_id'=>'driver_mapa_voice_demo_01'];
$baseline=[];foreach(['orders','payloads','tracking_numbers','tracking_statuses'] as $table)$baseline[$table]=DB::table($table)->count();
foreach([['deleted_at'=>now()],['company_uuid'=>$foreign],['meta'=>json_encode(['mapa_demo'=>true,'isolated_test'=>true])],['_key'=>'mapa-voice-v1','public_id'=>'place_mapa_voice_'.str_repeat('a',32),'meta'=>json_encode(['mapa_demo'=>false,'isolated_test'=>true,'mapa_voice_call_id'=>$boundaryCall['id'],'mapa_voice_source'=>'dispatcher_confirmed'])]] as $change){
    // Keep the reviewed public ID pointed at the changed row for generated-pickup case.
    $reviewState=$boundaryReady['call']['review'];if(isset($change['public_id']))$reviewState['dropoff_id']=$change['public_id'];
    DB::table('mapa_voice_calls')->where('id',$boundaryCall['id'])->update(['review'=>json_encode($reviewState)]);
    DB::table('places')->where('uuid',$receiving)->update($change);
    denied(fn()=>$s->mutate(voiceRequest([...$destinationDispatch,'action_id'=>(string)Str::uuid()],$tokens['admin']),'dispatch',$boundaryCall['id']),409);
    foreach($baseline as $table=>$count)check(DB::table($table)->count()===$count,'ineligible destination dispatch writes no '.$table);
    check(DB::table('mapa_voice_calls')->where('id',$boundaryCall['id'])->value('state')==='ready','ineligible destination leaves call ready for review');
    DB::table('places')->where('uuid',$receiving)->update(['deleted_at'=>null,'company_uuid'=>$company,'meta'=>$liveMeta,'_key'=>null,'public_id'=>$receivingId]);
}
DB::table('mapa_voice_calls')->where('id',$boundaryCall['id'])->update(['review'=>json_encode($boundaryReady['call']['review'])]);
$validDestinationDispatch=$s->mutate(voiceRequest([...$destinationDispatch,'action_id'=>(string)Str::uuid()],$tokens['admin']),'dispatch',$boundaryCall['id']);
check($validDestinationDispatch['call']['state']==='dispatched','restored eligible destination still dispatches using existing native flow');
