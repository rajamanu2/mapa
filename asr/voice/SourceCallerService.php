<?php
namespace App\MapaVoice;
use App\MapaMobile\SourceCallStore;
use MapaSource\SourceCallFeed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One tracking desk per source incident; never a second dispatch authority. */
class SourceCallerService extends VoiceService
{
    protected function sourceStore():SourceCallStore {return new SourceCallStore;}
    protected function sourceDirectory(string $company):array {return \App\MapaMobile\SourceInventoryController::verifiedState($company);}
    public function indexSource(Request $r):array {$c=$this->staff($r);return $this->sourceStore()->index($c['company']);}
    public function openSource(Request $r,string $id):array {
        $r->validate(['source_revision'=>'required|string|regex:/^[a-f0-9]{64}$/']);
        $c=$this->staff($r);$source=$this->sourceStore()->current($c['company'],$id,$r->input('source_revision'));
        return DB::transaction(function()use($r,$id,$c,$source){
            $this->staff($r,true);$this->lockTenant($c['company']);
            $call=DB::table('mapa_voice_calls')->where(['company_uuid'=>$c['company'],'provider'=>'asrhealth','provider_call_id'=>$id])->lockForUpdate()->first();
            $phone=$this->normalizeCallback($source['caller_phone']);
            $state=$this->sourceDirectory($c['company']);
            $hospital=array_column($state['snapshot']['datasets']['hospitals'],'name','phc_id');
            $secretariats=array_column($state['snapshot']['datasets']['secretariats']??[],'name','secretariat_id');
            $villages=array_column($state['snapshot']['datasets']['villages']??[],'name','village_id');
            $draft=['caller_name'=>$source['caller_name'],'callback_number'=>$phone,'patient_name'=>$source['patient_name'],
                'patient_age'=>$source['patient_age'],'patient_gender'=>in_array($source['patient_gender'],['Male','Female'],true)?$source['patient_gender']:'unknown',
                'reported_problem'=>$source['notes']?:$source['incident_type'],'incident_type'=>$source['incident_type'],
                'intake_priority'=>['P1'=>'emergency','P2'=>'urgent','P3'=>'routine'][$source['priority']]??'',
                'pickup_address'=>$source['pickup_address'],'origin_phc_id'=>$source['from_phc_id'],'origin_phc_name'=>$hospital[$source['from_phc_id']]??'',
                'origin_secretariat_id'=>$source['from_secretariat_id'],'origin_secretariat_name'=>$secretariats[$source['from_secretariat_id']]??'',
                'origin_village_id'=>$source['from_village_id'],'village'=>$villages[$source['from_village_id']]??'',
                'destination_phc_id'=>$source['to_phc_id'],'destination_facility_name'=>$source['destination_facility']?:($hospital[$source['to_phc_id']]??''),
                'dispatcher_notes'=>$source['notes'],'coordinates_confirmed'=>false,
                'source_context'=>['incident_id'=>$id,'incident_number'=>$source['incident_number'],'revision'=>SourceCallFeed::revision($source),'status'=>$source['status'],'owner'=>'asrhealth.in']];
            if(!$call){
                $uuid=(string)Str::uuid();DB::table('mapa_voice_calls')->insert(['id'=>$uuid,'company_uuid'=>$c['company'],'provider'=>'asrhealth','provider_call_id'=>$id,
                    'demo'=>false,'state'=>'ready','provider_status'=>'source_owned','revision'=>1,'last_sequence'=>0,'caller'=>$phone,'called'=>'',
                    'transcript'=>'','draft'=>json_encode($draft,JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]);
                $call=$this->call($uuid,$c['company']);$this->audit($call,$c['user']->uuid,'source_tracking_opened',['source_incident_id'=>$id]);
            }elseif((Policy::meta($call->draft)['source_context']['revision']??null)!==$r->input('source_revision')){
                if($call->order_uuid)self::fail('Source tracking record unexpectedly has a native dispatch.',409);
                DB::table('mapa_voice_calls')->where('id',$call->id)->update(['caller'=>$phone,'draft'=>json_encode($draft,JSON_THROW_ON_ERROR),'review'=>null,'revision'=>$call->revision+1,'updated_at'=>now()]);
                $call=$this->call($call->id,$c['company']);$this->audit($call,$c['user']->uuid,'source_tracking_refreshed',['source_incident_id'=>$id]);
            }
            return ['ok'=>true,'call'=>$this->view($call),'source_owned'=>true,'dispatch_created'=>false];
        },3);
    }
}
