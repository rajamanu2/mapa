<?php
/** Explicit local fictional crew fixture. Native query builder; no invitations or notifications. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require '/fleetbase/api/vendor/autoload.php';
$app=require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
try {
    if(!app()->environment('local','testing')||count($argv)!==2||!in_array($argv[1],['--apply','--verify'],true))throw new RuntimeException('Requires local CLI with --apply or --verify and private JSON input.');
    $raw=stream_get_contents(STDIN,8193);if(strlen($raw)>8192)throw new RuntimeException('Input too large.');
    $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);$company=(string)config('mapa.company_uuid');$dataset='mapa-voice-demo-v1';
    if(($input['dataset']??null)!==$dataset||($input['company_uuid']??null)!==$company||!DB::table('companies')->where('uuid',$company)->whereNull('deleted_at')->exists())throw new RuntimeException('Configured company and dataset required.');
    $email=$input['email']??'';$password=$input['password']??'';
    if($email!=='voice.crew.demo@mapa.invalid'||!is_string($password)||strlen($password)<24||strlen($password)>200)throw new RuntimeException('Fixed fictional email and generated private password required.');
    $apply=$argv[1]==='--apply';$now=now()->toDateTimeString();$created=$unchanged=0;
    $id=static function($key)use($company,$dataset){$h=hash('sha256',"$dataset:$company:$key");return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-a'.substr($h,17,3).'-'.substr($h,20,12);};
    $meta=json_encode(['mapa_demo'=>true,'simulated'=>true,'demo_dataset'=>$dataset,'mapa_ambulance_level'=>'bls','notice'=>'Fictional voice training fixture. No actual person, patient or ambulance.'],JSON_THROW_ON_ERROR);
    DB::transaction(function()use($company,$dataset,$email,$password,$apply,$now,$id,$meta,&$created,&$unchanged){
        DB::table('companies')->where('uuid',$company)->lockForUpdate()->first();
        $put=function($table,$key,$values,$fixed)use($company,$dataset,$apply,$now,$id,$meta,&$created,&$unchanged){
            $uuid=$id($key);$row=DB::table($table)->where('uuid',$uuid)->lockForUpdate()->first();
            if($row){
                if($row->company_uuid!==$company||$row->_key!==$dataset||$row->deleted_at!==null)throw new RuntimeException('Existing fixture ownership changed.');
                if(property_exists($row,'meta')){$m=json_decode($row->meta??'{}',true);if(($m['mapa_demo']??null)!==true||($m['demo_dataset']??null)!==$dataset)throw new RuntimeException('DEMO metadata mismatch.');}
                foreach($fixed as $field)if((string)($row->$field??'')!==(string)($values[$field]??''))throw new RuntimeException('Existing fixture identity changed; nothing reset.');
                $unchanged++;
            }else{
                if(!$apply)throw new RuntimeException('Fixture missing; --apply required.');
                if(isset($values['public_id'])&&DB::table($table)->where('public_id',$values['public_id'])->exists())throw new RuntimeException('Public ID conflict.');
                $base=['uuid'=>$uuid,'company_uuid'=>$company,'_key'=>$dataset,'created_at'=>$now,'updated_at'=>$now];
                if(Schema::hasColumn($table,'meta'))$base['meta']=$meta;
                DB::table($table)->insert([...$base,...$values]);$created++;
            }return $uuid;
        };
        if(DB::table('users')->where('email',$email)->where('uuid','<>',$id('user'))->exists())throw new RuntimeException('Email conflict.');
        $user=$put('users','user',['public_id'=>'user_mapa_voice_demo_01','name'=>'DEMO Voice Crew','email'=>$email,'phone'=>null,'password'=>Hash::make($password),'status'=>'active','type'=>'driver','email_verified_at'=>$now,'country'=>'IN','timezone'=>'Asia/Kolkata'],['public_id','name','email','status','type']);
        $saved=DB::table('users')->where('uuid',$user)->first();if(!$saved->email_verified_at||!Hash::check($password,$saved->password))throw new RuntimeException('Credential mismatch; no reset performed.');
        if(DB::table('company_users')->where(['company_uuid'=>$company,'user_uuid'=>$user])->where('uuid','<>',$id('membership'))->exists())throw new RuntimeException('Membership conflict.');
        $put('company_users','membership',['user_uuid'=>$user,'status'=>'active','external'=>0],['user_uuid','status']);
        $point=DB::raw("ST_GeomFromText('POINT(82.669 18.083)', 0)");
        $vehicle=$put('vehicles','vehicle',['public_id'=>'vehicle_mapa_voice_demo_01','internal_id'=>'DEMO-VOICE-AMB-01','name'=>'DEMO Voice Ambulance — Paderu','description'=>'Fictional BLS voice intake demonstration.','plate_number'=>'DEMO-VOICE-01','call_sign'=>'DEMO-VOICE-01','status'=>'active','online'=>0,'location'=>$point,'slug'=>'mapa-voice-demo-ambulance'],['public_id','internal_id','plate_number']);
        $driver=$put('drivers','driver',['public_id'=>'driver_mapa_voice_demo_01','internal_id'=>'DEMO-VOICE-CREW-01','user_uuid'=>$user,'vehicle_uuid'=>$vehicle,'location'=>$point,'latitude'=>18.083,'longitude'=>82.669,'country'=>'IN','city'=>'Paderu','currency'=>'INR','online'=>0,'status'=>'available','current_status'=>'DEMO voice pilot — simulated','slug'=>'mapa-voice-demo-crew'],['public_id','user_uuid','vehicle_uuid']);
        if(DB::table('drivers')->where('vehicle_uuid',$vehicle)->where('uuid','<>',$driver)->whereNull('deleted_at')->exists())throw new RuntimeException('Vehicle conflict.');
        $expected=['id'=>$id('binding'),'company_uuid'=>$company,'user_uuid'=>$user,'role'=>'crew','driver_uuid'=>$driver,'place_uuid'=>null,'enabled'=>1,'revision'=>1];
        $binding=DB::table('mapa_mobile_bindings')->where(['company_uuid'=>$company,'user_uuid'=>$user])->lockForUpdate()->first();
        if($binding){foreach($expected as $field=>$value)if((string)($binding->$field??'')!==(string)($value??''))throw new RuntimeException('Binding conflict.');$unchanged++;}
        else{if(!$apply)throw new RuntimeException('Binding missing.');DB::table('mapa_mobile_bindings')->insert([...$expected,'created_at'=>$now,'updated_at'=>$now]);$created++;}
    });
    echo json_encode(['dataset'=>$dataset,'simulated'=>true,'created'=>$created,'unchanged'=>$unchanged,'driver_id'=>'driver_mapa_voice_demo_01','vehicle_id'=>'vehicle_mapa_voice_demo_01','notifications_sent'=>0],JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,get_class($e)===RuntimeException::class?$e->getMessage():('Provisioning failed; transaction rolled back ('.get_class($e).').'));exit(1);}
