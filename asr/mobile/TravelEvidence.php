<?php
namespace App\MapaMobile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read accepted GPS receipts only; never infer missing fixes from a map position. */
class TravelEvidence
{
    public function history(string $company,string $driver,?string $vehicle=null,?string $user=null): array
    {
        $q=DB::table('mapa_mobile_receipts')->where('company_uuid',$company)
            ->where('created_at','>=',now()->subDay())->where('result->gps_evidence->driver_id',$driver);
        if($vehicle!==null)$q->where('result->gps_evidence->vehicle_id',$vehicle);
        if($user!==null)$q->where('user_uuid',$user);
        $rows=$q->orderByDesc('id')->limit(201)->get();$points=[];
        foreach($rows->take(200)->reverse() as $row){
            $e=json_decode($row->result,true,512,JSON_THROW_ON_ERROR)['gps_evidence']??null;
            if(is_array($e)&&($e['source']??'')==='device_gps')$points[]=$e;
        }
        return ['points'=>$points,'window_hours'=>24,'limit'=>200,'truncated'=>$rows->count()>200,
            'proof_of_travel'=>false,'notice'=>'Device-reported locations; gaps, accuracy and device handling affect interpretation. No person or completed journey is verified.'];
    }
    public function inspect(Request $r,array $c): array
    {
        $role=$c['binding']->role;
        if(!in_array($role,['crew','admin','superadmin'],true))MobileService::fail('Crew location access is not available for this role.');
        $v=$r->validate(['driver_id'=>'sometimes|string|max:100']);$id=$v['driver_id']??null;
        if($role==='crew'){
            if($id!==null&&$id!==$c['driver']->public_id)MobileService::fail('Crew location is outside this assignment.',404);
            return ['contract'=>Policy::CONTRACT,'travel_evidence'=>$c['vehicle']?$this->history($c['company'],$c['driver']->public_id,$c['vehicle']->public_id,$c['user']->uuid):null];
        }
        $q=DB::table('drivers as d')->leftJoin('users as u',function($join)use($c){$join->on('u.uuid','=','d.user_uuid')->where('u.company_uuid',$c['company'])->whereNull('u.deleted_at');})
            ->where('d.company_uuid',$c['company'])->whereNull('d.deleted_at');
        if($id===null)return ['contract'=>Policy::CONTRACT,'drivers'=>$q->orderBy('d.public_id')->limit(200)->get(['d.public_id','u.name'])->map(fn($d)=>['id'=>$d->public_id,'name'=>$d->name??$d->public_id])->all(),'travel_evidence'=>null];
        $d=$q->where('d.public_id',$id)->first(['d.public_id','u.name']);if(!$d)MobileService::fail('Crew not found.',404);
        return ['contract'=>Policy::CONTRACT,'driver'=>['id'=>$d->public_id,'name'=>$d->name],'travel_evidence'=>$this->history($c['company'],$d->public_id)];
    }
}
