<?php
namespace App\MapaMobile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Model-based legacy/background writes cannot invalidate coordinated ownership. */
class SourceNativeAssignmentObserver
{
    private function enabled():bool{return (bool)config('mapa_mobile.source_dispatch_coordination_enabled',false);}
    private function refused():never{throw new HttpException(409,'Use the reviewed call or ASR integration workflow for this fleet change.');}
    private function held($model):bool
    {
        $field=match($model->getTable()){'drivers'=>'driver_uuid','vehicles'=>'vehicle_uuid',default=>null};
        if(!$field)return false;
        $company=$model->getRawOriginal('company_uuid')??$model->company_uuid;
        $uuid=$model->getRawOriginal('uuid')??$model->uuid;
        try{return DB::table('mapa_source_journeys')->where('company_uuid',$company)->where($field,$uuid)->whereNull('terminal_at')->exists();}
        catch(\Throwable $e){throw new HttpException(503,'Fleet coordination is unavailable. Contact control.');}
    }
    public function creating($model):void{if($this->enabled()&&$model->getTable()==='orders')$this->refused();}
    public function updating($model):void
    {
        if(!$this->enabled())return;
        $table=$model->getTable();
        if($table==='orders'&&$model->isDirty(['uuid','company_uuid','driver_assigned_uuid','vehicle_assigned_uuid','status','dispatched','started','dispatched_at','started_at','payload_uuid','order_config_uuid']))$this->refused();
        $protected=match($table){'drivers'=>['uuid','vehicle_uuid','user_uuid','current_job_uuid','company_uuid'],'vehicles'=>['uuid','plate_number','vin','status','company_uuid'],default=>[]};
        if($protected&&$model->isDirty($protected))$this->refused();
    }
    public function deleting($model):void{if($this->enabled()&&($model->getTable()==='orders'||$this->held($model)))$this->refused();}
}
