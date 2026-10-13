<?php
namespace App\MapaMobile;
require_once __DIR__.'/SourceCommandController.php';
require_once __DIR__.'/SourceMasterClient.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
class SourceMasterController extends SourceCommandController {
    protected function protocol():string{return \MapaSource\SourceMasterCommands::class;}
    protected function configured():bool{return (bool)config('mapa_mobile.source_sync_enabled',false)&&(bool)config('mapa_mobile.source_masters_enabled',false)&&$this->client()->configured();}
    protected function client():SourceCommandClient{return new SourceMasterClient(storage_path('app/mapa-source-sync/master.json'));}
    protected function send(array $command):array {
        if(str_ends_with($command['action'],'.delete')){
            if(!Schema::hasTable('mapa_source_connections'))throw new SourceCommandUnavailable('Record connection safety is unavailable.');
            $dataset=str_starts_with($command['action'],'driver.')?'drivers':'ambulances';
            if(DB::table('mapa_source_connections')->where(['company_uuid'=>$command['company_id'],'dataset'=>$dataset,'source_id'=>$command['record_id'],'active'=>true])->exists())throw new SourceCommandRefused(409,'Disconnect and review the MAPA record before deleting its source record.');
        }
        return parent::send($command);
    }
}
